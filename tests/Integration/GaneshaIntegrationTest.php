<?php

declare(strict_types=1);

namespace Zeusi\GaneshaApcuAdapter\Tests\Integration;

use Ackintosh\Ganesha\Builder;
use PHPUnit\Framework\TestCase;
use Zeusi\GaneshaApcuAdapter\SlidingTimeWindowApcu;

final class GaneshaIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        apcu_clear_cache();
    }

    public function testCircuitTripsAndRecoversWithRateStrategy(): void
    {
        $ganesha = Builder::withRateStrategy()
            ->adapter(new SlidingTimeWindowApcu())
            ->timeWindow(60)
            ->failureRateThreshold(50)
            ->minimumRequests(4)
            ->intervalToHalfOpen(1)
            ->build();

        $ganesha->success('service');
        $ganesha->failure('service');
        $ganesha->failure('service');
        $ganesha->failure('service');

        self::assertFalse($ganesha->isAvailable('service'));
        self::assertTrue($ganesha->isAvailable('other-service'));

        sleep(2);
        self::assertTrue($ganesha->isAvailable('service'), 'A trial request is allowed once the circuit is half-open');
        self::assertFalse($ganesha->isAvailable('service'), 'Only one trial request is allowed per half-open interval');

        $ganesha->reset();
        self::assertTrue($ganesha->isAvailable('service'));
    }
}
