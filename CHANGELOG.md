# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-10

- Initial release: `SlidingTimeWindowApcu`, an APCu storage adapter for Ganesha's
  Rate strategy with a sliding time window, counting events in buckets of
  configurable duration (at most 120 per time window) with atomic `apcu_inc()`
  writes, for Ganesha 3.x and 4.x.

[0.1.0]: https://github.com/antonioturdo/ganesha-apcu-adapter/releases/tag/0.1.0
