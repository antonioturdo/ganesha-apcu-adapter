<?php

declare(strict_types=1);

namespace Zeusi\GaneshaApcuAdapter;

use Ackintosh\Ganesha;
use Ackintosh\Ganesha\Configuration;
use Ackintosh\Ganesha\Context;
use Ackintosh\Ganesha\Exception\StorageException;
use Ackintosh\Ganesha\Storage\Adapter\SlidingTimeWindowInterface;
use Ackintosh\Ganesha\Storage\AdapterInterface;
use Ackintosh\Ganesha\Storage\StorageKeysInterface;

/**
 * APCu adapter for the Rate strategy with a sliding time window.
 *
 * Events are counted in fixed-duration buckets, one APCu entry per bucket, so every
 * write is an atomic `apcu_inc()`. A count covers the buckets spanning the time window,
 * the current one included: since the current bucket is partial, the covered interval
 * differs from the time window by less than one bucket.
 */
final class SlidingTimeWindowApcu implements AdapterInterface, SlidingTimeWindowInterface
{
    /**
     * Maximum number of buckets a time window can be split into.
     */
    public const MAX_BUCKETS = 120;

    /**
     * Number of buckets targeted when no bucket duration is given.
     */
    public const DEFAULT_BUCKETS = 60;

    private const LAST_FAILURE_TIME_SUFFIX = '.last_failure_time';

    /**
     * The status only drives Ganesha's notifications: it is kept long enough not to send
     * them again while a circuit stays open, and expires for services no longer in use.
     */
    private const STATUS_TTL = 86_400;

    private ?int $configuredBucketDuration;

    private int $bucketDuration;

    private int $bucketCount;

    private StorageKeysInterface $storageKeys;

    /**
     * @param int|null $bucketDuration Bucket duration in seconds. It must not exceed the time window
     *                                 nor split it into more than MAX_BUCKETS buckets. When null,
     *                                 the time window is split into DEFAULT_BUCKETS buckets, or into
     *                                 one-second buckets if it is shorter.
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(?int $bucketDuration = null)
    {
        if ($bucketDuration !== null && $bucketDuration < 1) {
            throw new \InvalidArgumentException('The bucket duration must be a positive integer.');
        }

        $this->configuredBucketDuration = $bucketDuration;
    }

    public function supportCountStrategy(): bool
    {
        return false;
    }

    public function supportRateStrategy(): bool
    {
        return true;
    }

    /**
     * @throws \InvalidArgumentException when the bucket duration does not fit the time window
     */
    public function setContext(Context $context): void
    {
        $configuration = $context->configuration();
        $timeWindow = $configuration->timeWindow();

        $this->bucketDuration = $this->configuredBucketDuration ?? (int) ceil($timeWindow / self::DEFAULT_BUCKETS);

        if ($this->bucketDuration > $timeWindow) {
            throw new \InvalidArgumentException(\sprintf(
                'The bucket duration (%d s) must not exceed the time window (%d s).',
                $this->bucketDuration,
                $timeWindow,
            ));
        }

        $this->bucketCount = (int) ceil($timeWindow / $this->bucketDuration);

        if ($this->bucketCount > self::MAX_BUCKETS) {
            throw new \InvalidArgumentException(\sprintf(
                'A bucket duration of %d s splits the time window (%d s) into %d buckets, the maximum is %d.',
                $this->bucketDuration,
                $timeWindow,
                $this->bucketCount,
                self::MAX_BUCKETS,
            ));
        }

        $this->storageKeys = $configuration->storageKeys();
    }

    public function setConfiguration(Configuration $configuration): void
    {
        // nop: the configuration is read from the context
    }

    public function load(string $service): int
    {
        $currentBucket = $this->currentBucket();
        $keys = [];
        for ($bucket = $currentBucket - $this->bucketCount + 1; $bucket <= $currentBucket; $bucket++) {
            $keys[] = $this->bucketKey($service, $bucket);
        }

        $counts = apcu_fetch($keys);
        if (!\is_array($counts)) {
            throw new StorageException('Failed to load the count. service: ' . $service);
        }

        return (int) array_sum($counts);
    }

    /**
     * @throws \LogicException this adapter does not support the Count strategy
     */
    public function save(string $service, int $count): void
    {
        throw new \LogicException(self::class . ' does not support the Count strategy.');
    }

    public function increment(string $service): void
    {
        apcu_inc($this->bucketKey($service, $this->currentBucket()), 1, $success, $this->windowTtl());

        if (!$success) {
            throw new StorageException('Failed to increment the count. service: ' . $service);
        }
    }

    /**
     * @throws \LogicException this adapter does not support the Count strategy
     */
    public function decrement(string $service): void
    {
        throw new \LogicException(self::class . ' does not support the Count strategy.');
    }

    public function saveLastFailureTime(string $service, int $lastFailureTime): void
    {
        // Every failure rewrites it: once expired, no failure is left in the window and the circuit is closed
        if (!apcu_store($service . self::LAST_FAILURE_TIME_SUFFIX, $lastFailureTime, $this->windowTtl())) {
            throw new StorageException('Failed to save the last failure time. service: ' . $service);
        }
    }

    public function loadLastFailureTime(string $service): ?int
    {
        $lastFailureTime = apcu_fetch($service . self::LAST_FAILURE_TIME_SUFFIX, $success);

        return $success ? (int) $lastFailureTime : null;
    }

    public function saveStatus(string $service, int $status): void
    {
        if (!apcu_store($service, $status, self::STATUS_TTL)) {
            throw new StorageException(\sprintf('Failed to save the status. service: %s, status: %d', $service, $status));
        }
    }

    public function loadStatus(string $service): int
    {
        $status = apcu_fetch($service, $success);

        // A missing status (never stored, expired or reset) is the initial one, with no need to store it
        return $success ? (int) $status : Ganesha::STATUS_CALMED_DOWN;
    }

    /**
     * Deletes the counts, last failure times and statuses of every service.
     */
    public function reset(): void
    {
        // Nothing can be stored while APCu is disabled, and APCUIterator would throw an Error
        if (!apcu_enabled()) {
            return;
        }

        $suffixes = array_map(
            static fn(string $suffix): string => preg_quote($suffix, '/'),
            [
                $this->storageKeys->success(),
                $this->storageKeys->failure(),
                $this->storageKeys->rejection(),
                $this->storageKeys->status(),
            ],
        );

        $keyRegex = \sprintf(
            '/^%s.+(%s)(\\.\\d+|%s)?$/',
            preg_quote($this->storageKeys->prefix(), '/'),
            implode('|', $suffixes),
            preg_quote(self::LAST_FAILURE_TIME_SUFFIX, '/'),
        );

        apcu_delete(new \APCUIterator($keyRegex, APC_ITER_KEY));
    }

    /**
     * Keeps an entry until the bucket it was written in leaves the time window.
     */
    private function windowTtl(): int
    {
        return ($this->bucketCount + 1) * $this->bucketDuration;
    }

    private function currentBucket(): int
    {
        return intdiv(time(), $this->bucketDuration);
    }

    private function bucketKey(string $service, int $bucket): string
    {
        return $service . '.' . $bucket;
    }
}
