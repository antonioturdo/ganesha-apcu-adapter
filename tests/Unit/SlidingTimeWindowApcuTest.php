<?php

declare(strict_types=1);

namespace Zeusi\GaneshaApcuAdapter\Tests\Unit;

use Ackintosh\Ganesha;
use Ackintosh\Ganesha\Configuration;
use Ackintosh\Ganesha\Context;
use Ackintosh\Ganesha\Storage\StorageKeys;
use Ackintosh\Ganesha\Storage\StorageKeysInterface;
use Ackintosh\Ganesha\Strategy\Rate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Zeusi\GaneshaApcuAdapter\SlidingTimeWindowApcu;
use Zeusi\GaneshaApcuAdapter\Tests\Fixtures\RegexMetacharacterStorageKeys;

final class SlidingTimeWindowApcuTest extends TestCase
{
    private const SUCCESS_KEY = 'ganesha_service_success';

    private const FAILURE_KEY = 'ganesha_service_failure';

    private const STATUS_KEY = 'ganesha_service_status';

    private const CONCURRENT_PROCESSES = 8;

    private const INCREMENTS_PER_PROCESS = 1_000;

    protected function tearDown(): void
    {
        apcu_clear_cache();
    }

    public function testSupportsOnlyTheRateStrategy(): void
    {
        $adapter = new SlidingTimeWindowApcu();

        self::assertTrue($adapter->supportRateStrategy());
        self::assertFalse($adapter->supportCountStrategy());
    }

    public function testRejectsNonPositiveBucketDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SlidingTimeWindowApcu(0);
    }

    /**
     * @return iterable<string, array{int, int|null}>
     */
    public static function validBucketDurationProvider(): iterable
    {
        yield 'default, window shorter than the default bucket count' => [30, null];
        yield 'default, window longer than the default bucket count' => [600, null];
        yield 'default, window not divisible by the default bucket count' => [61, null];
        yield 'explicit, maximum bucket count' => [120, 1];
        yield 'explicit, not a divisor of the time window' => [10, 3];
        yield 'explicit, single bucket' => [600, 600];
    }

    #[DataProvider('validBucketDurationProvider')]
    public function testCountsEventsWithValidBucketDuration(int $timeWindow, ?int $bucketDuration): void
    {
        $adapter = self::createAdapter($timeWindow, $bucketDuration);

        $adapter->increment(self::FAILURE_KEY);
        $adapter->increment(self::FAILURE_KEY);

        self::assertSame(2, $adapter->load(self::FAILURE_KEY));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidBucketDurationProvider(): iterable
    {
        yield 'longer than the time window' => [10, 20];
        yield 'too many buckets' => [600, 1];
        yield 'too many buckets, not a divisor of the time window' => [241, 2];
    }

    #[DataProvider('invalidBucketDurationProvider')]
    public function testRejectsBucketDurationNotFittingTheTimeWindow(int $timeWindow, int $bucketDuration): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::createAdapter($timeWindow, $bucketDuration);
    }

    public function testEventsLeaveTheWindowAsItSlides(): void
    {
        $adapter = self::createAdapter(2, 1);
        self::waitForNextSecond();

        $adapter->saveLastFailureTime(self::FAILURE_KEY, time());
        $adapter->increment(self::FAILURE_KEY);
        $adapter->increment(self::SUCCESS_KEY);
        sleep(1);
        $adapter->increment(self::FAILURE_KEY);

        self::assertSame(2, $adapter->load(self::FAILURE_KEY));
        self::assertSame(1, $adapter->load(self::SUCCESS_KEY));

        sleep(1);
        self::assertSame(1, $adapter->load(self::FAILURE_KEY));
        self::assertSame(0, $adapter->load(self::SUCCESS_KEY));

        sleep(1);
        self::assertSame(0, $adapter->load(self::FAILURE_KEY));
        self::assertNotNull($adapter->loadLastFailureTime(self::FAILURE_KEY));

        sleep(1);
        self::assertNull($adapter->loadLastFailureTime(self::FAILURE_KEY));
    }

    public function testStoresStatusAndLastFailureTime(): void
    {
        $adapter = self::createAdapter(60);

        self::assertSame(Ganesha::STATUS_CALMED_DOWN, $adapter->loadStatus(self::STATUS_KEY));
        self::assertNull($adapter->loadLastFailureTime(self::FAILURE_KEY));

        $adapter->saveStatus(self::STATUS_KEY, Ganesha::STATUS_TRIPPED);
        $adapter->saveLastFailureTime(self::FAILURE_KEY, 1_700_000_000);

        self::assertSame(Ganesha::STATUS_TRIPPED, $adapter->loadStatus(self::STATUS_KEY));
        self::assertSame(1_700_000_000, $adapter->loadLastFailureTime(self::FAILURE_KEY));
    }

    /**
     * @return iterable<string, array{StorageKeysInterface}>
     */
    public static function storageKeysProvider(): iterable
    {
        yield 'default storage keys' => [new StorageKeys()];
        yield 'storage keys with regex metacharacters' => [new RegexMetacharacterStorageKeys()];
    }

    #[DataProvider('storageKeysProvider')]
    public function testResetDeletesOnlyGaneshaEntries(StorageKeysInterface $storageKeys): void
    {
        $adapter = self::createAdapter(60, storageKeys: $storageKeys);
        $failureKey = $storageKeys->prefix() . 'service' . $storageKeys->failure();
        $successKey = $storageKeys->prefix() . 'service' . $storageKeys->success();
        $statusKey = $storageKeys->prefix() . 'service' . $storageKeys->status();
        $adapter->increment($failureKey);
        $adapter->increment($successKey);
        $adapter->saveLastFailureTime($failureKey, 1_700_000_000);
        $adapter->saveStatus($statusKey, Ganesha::STATUS_TRIPPED);
        apcu_store($storageKeys->prefix() . 'unrelated', 'value');
        apcu_store('application_key', 'value');

        $adapter->reset();

        self::assertSame(0, $adapter->load($failureKey));
        self::assertSame(0, $adapter->load($successKey));
        self::assertNull($adapter->loadLastFailureTime($failureKey));
        self::assertSame(Ganesha::STATUS_CALMED_DOWN, $adapter->loadStatus($statusKey));
        self::assertTrue(apcu_exists($storageKeys->prefix() . 'unrelated'));
        self::assertTrue(apcu_exists('application_key'));
    }

    #[RequiresPhpExtension('pcntl')]
    public function testConcurrentIncrementsAreNotLost(): void
    {
        $adapter = self::createAdapter(60);

        $pids = [];
        for ($process = 0; $process < self::CONCURRENT_PROCESSES; $process++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                // Child process: it shares the APCu memory with the parent
                try {
                    for ($i = 0; $i < self::INCREMENTS_PER_PROCESS; $i++) {
                        $adapter->increment(self::FAILURE_KEY);
                    }
                } catch (\Throwable) {
                    exit(1);
                }
                exit(0);
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        self::assertSame(self::CONCURRENT_PROCESSES * self::INCREMENTS_PER_PROCESS, $adapter->load(self::FAILURE_KEY));
    }

    /**
     * @return iterable<string, array{\Closure(SlidingTimeWindowApcu): void}>
     */
    public static function countStrategyOperationProvider(): iterable
    {
        yield 'save' => [static fn(SlidingTimeWindowApcu $adapter) => $adapter->save(self::FAILURE_KEY, 1)];
        yield 'decrement' => [static fn(SlidingTimeWindowApcu $adapter) => $adapter->decrement(self::FAILURE_KEY)];
    }

    /**
     * @param \Closure(SlidingTimeWindowApcu): void $operation
     */
    #[DataProvider('countStrategyOperationProvider')]
    public function testRejectsCountStrategyOperations(\Closure $operation): void
    {
        $this->expectException(\LogicException::class);

        $operation(self::createAdapter(60));
    }

    private static function createAdapter(
        int $timeWindow,
        ?int $bucketDuration = null,
        StorageKeysInterface $storageKeys = new StorageKeys(),
    ): SlidingTimeWindowApcu {
        $adapter = new SlidingTimeWindowApcu($bucketDuration);
        $configuration = new Configuration([
            Configuration::TIME_WINDOW => $timeWindow,
            Configuration::STORAGE_KEYS => $storageKeys,
        ]);
        $adapter->setContext(new Context(Rate::class, $adapter, $configuration));

        return $adapter;
    }

    /**
     * Aligns the test with the start of a second, so that one-second sleeps cross exactly one bucket.
     */
    private static function waitForNextSecond(): void
    {
        $now = microtime(true);
        usleep((int) ((ceil($now) - $now) * 1_000_000) + 10_000);
    }
}
