# Ganesha APCu Adapter

[![Packagist Version](https://img.shields.io/packagist/v/zeusi/ganesha-apcu-adapter.svg)](https://packagist.org/packages/zeusi/ganesha-apcu-adapter)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4.svg)](https://www.php.net/)
[![CI](https://github.com/antonioturdo/ganesha-apcu-adapter/actions/workflows/ci.yml/badge.svg)](https://github.com/antonioturdo/ganesha-apcu-adapter/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/phpstan-level%208-brightgreen.svg)](https://phpstan.org/)
[![Coverage](https://codecov.io/gh/antonioturdo/ganesha-apcu-adapter/graph/badge.svg)](https://codecov.io/gh/antonioturdo/ganesha-apcu-adapter)
[![License](https://img.shields.io/packagist/l/zeusi/ganesha-apcu-adapter.svg)](LICENSE)

> An APCu storage adapter for [Ganesha](https://github.com/ackintosh/ganesha) that implements the **sliding time window** Rate strategy.

Ganesha already ships an APCu adapter, but it only supports the tumbling time window. This package provides the sliding time window variant, so circuit state is evaluated over the last `timeWindow` seconds instead of fixed, consecutive buckets, without requiring an external store such as Redis.

## Installation

```bash
composer require zeusi/ganesha-apcu-adapter
```

Requires the `apcu` extension (and `apc.enable_cli=1` to use it from the CLI).

## Usage

```php
use Ackintosh\Ganesha\Builder;
use Zeusi\GaneshaApcuAdapter\SlidingTimeWindowApcu;

$ganesha = Builder::withRateStrategy()
    ->adapter(new SlidingTimeWindowApcu())
    ->timeWindow(60)
    ->failureRateThreshold(50)
    ->minimumRequests(10)
    ->intervalToHalfOpen(5)
    ->build();
```

Only the Rate strategy is supported: for the Count strategy use the `Apcu` adapter shipped with Ganesha.

## Circuit state is local

APCu is shared memory owned by a single PHP master process, so the circuit state is shared only by the workers of that process. Each of the following has its own, independent circuits:

- every server;
- every PHP-FPM pool, and every PHP-FPM master on the same server;
- CLI commands, each running in its own process;
- long-running worker runtimes, such as RoadRunner or FrankenPHP in worker mode, depending on how they spawn their processes.

A failing service therefore trips a separate circuit on each server, after `minimumRequests` requests seen by that server. When circuits must be shared across servers, use a centralized store, such as Ganesha's Redis adapter.

## Sliding vs tumbling time window

Ganesha's own `Apcu` adapter counts events in consecutive, fixed time windows aligned to the clock (tumbling), while this adapter counts them over the last `timeWindow` seconds (sliding). The difference shows when failures straddle a window boundary.

With `timeWindow(60)`, `failureRateThreshold(50)` and `minimumRequests(10)`, suppose a service fails every request from 10:00:55 to 10:01:04, 12 requests in total:

| | Tumbling (`Apcu`) | Sliding (`SlidingTimeWindowApcu`) |
|---|---|---|
| Requests seen | 6 in the 10:00 window, 6 in the 10:01 window | 12 in the last 60 seconds |
| Outcome | Neither window reaches `minimumRequests`: the circuit never trips | 100% failure rate over 12 requests: the circuit trips |

Recovery differs too: a tumbling circuit stays open until both the current and the previous window are below the threshold, up to about two windows, while a sliding circuit closes as soon as the failure rate over the last `timeWindow` seconds drops below the threshold.

Ganesha's `Apcu` adapter remains the choice for the Count strategy, and uses fewer APCu entries: three counters per service and window, instead of one entry per bucket.

## Half-open state

Once `intervalToHalfOpen` seconds have passed since the last failure, Ganesha lets a trial request through and resets the interval. This adapter stores that reset, so **one trial request per interval** reaches the failing service while the others are still rejected.

Ganesha's Redis adapter ignores the reset instead: after the interval, every request goes through until one of them fails. With a service timing out after 5 seconds and 100 requests per second, this sends about 500 requests to a service that may still be failing, each holding a PHP worker until the timeout, again at every interval.

The trade-off is a slower recovery. Ganesha's Rate strategy does not close the circuit after a successful trial: it closes only when the failure rate over the time window drops below the threshold. A successful trial adds a single success, so the circuit may stay open up to about one time window after the last failure, even if the service has already recovered.

In practice, trials mostly act as a probe: a failed trial adds a failure and keeps the circuit open, while successful trials rarely close it before the failures leave the time window. `timeWindow` therefore roughly sets how long the circuit stays open, and lowering `intervalToHalfOpen` relative to it is the way to speed up recovery: with `timeWindow(60)` and `intervalToHalfOpen(1)`, up to 60 successful trials per window can outweigh the failures, still sending a single request per second to the failing service.

Since the state is local, "one trial per interval" applies to each server. Moreover, the check and the reset are two separate operations, so concurrent requests arriving at the same moment may let a few trials through.

## Buckets

Events are counted in fixed-duration buckets, one APCu entry per bucket, so every write is a single atomic `apcu_inc()`. A count covers the buckets spanning the time window, the current one included: since the current bucket is partial, the covered interval differs from the time window by less than one bucket.

The bucket duration (in seconds) can be passed to the constructor:

```php
new SlidingTimeWindowApcu(bucketDuration: 5);
```

It must not exceed the time window nor split it into more than 120 buckets: an invalid duration is rejected when Ganesha is built. When omitted, the time window is split into 60 buckets, or into one-second buckets if it is shorter than 60 seconds, for example:

| Time window | Default bucket duration | Buckets |
|---|---|---|
| 30 s | 1 s | 30 |
| 61 s | 2 s | 31 |
| 600 s | 10 s | 60 |

## APCu memory

Each bucket is a separate APCu entry with a TTL, so a new entry is created for every counter (success, failure and rejection) of every service at each bucket with traffic. A service keeps at most `(buckets + 1) × 3` bucket entries alive, plus its last failure time (kept as long as the buckets) and, once its circuit has tripped, its status (kept for one day): for example, 50 services with 60 buckets take about 9,000 entries, roughly 1.5 MB.

APCu has no background garbage collection, but removes expired entries from a hash slot whenever it inserts into that slot: since buckets are inserted continuously, expired buckets do not pile up beyond the order of the number of slots. Every entry has nearly the same size, so freed memory is reused without fragmentation.

Operational notes:

- With many services, raise `apc.entries_hint` (default 4096) to keep hash chains short.
- When the cache is full, APCu versions before 5.1.25 with the default `apc.ttl=0` wipe the entire cache instead of removing expired entries first. This adapter's footprint is bounded, but if the rest of the application fills the cache, the circuit state is lost and every circuit restarts closed.

## License

MIT, see [LICENSE](LICENSE).
