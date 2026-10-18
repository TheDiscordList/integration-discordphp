# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0] - 2026-10-18

### Added

- `listing.bump_ready` in the documented webhook events.
- `WebhookHandler` awaits a ReactPHP promise returned by the callback for up to
  8 seconds (`timeout:`) when served by `HttpServer`: `204` on fulfilment, `500`
  on rejection, `202` past the budget. Every rejection reaches `onError`.

### Changed

- `Verifier::verify()` and `WebhookHandler` throw an `InvalidArgumentException`
  for a blank secret instead of computing an HMAC with an empty key.
- `WebhookHandler` answers `401` instead of `400` for a missing or invalid
  signature, and `413` for a body over 1 MiB.
- Heartbeats chain one-shot timers with 0–5 seconds of jitter instead of a fixed
  periodic timer, and an out-of-band beat waits until at least 10 seconds after
  the previous one.
- `408` and `425` are retried like `5xx`; every other `4xx` is reported and not
  retried. A `429` is retried once its wait is over if that comes before the
  next run, and its suspension is capped at an hour.
- Suspensions and gaps run on the monotonic `hrtime()` clock.

## [0.1.0] - 2026-10-15

### Added

- `Autoposter::attach()` for DiscordPHP v10.1+: stats (server and shard count),
  per-shard heartbeats and global application commands, posted on DiscordPHP's
  own event loop through react/http, never blocking it.
- Shard topology read from DiscordPHP's `shard` / `shard_id` + `num_shards` /
  `shardId` + `shardCount` options, a `cluster` override, and a `serverCount`
  callback for multi-process bots.
- Status handling per the TheDiscordList API contract: 401 disables everything,
  403 suspends heartbeats for 30 minutes, 404/409 disable one publisher, one
  shared 429 suspension, transient failures retried with backoff.
- `posted`, `error` and `disabled` events, and `postStats()`, `postHeartbeat()`,
  `postCommands()` that resolve a `PostResult` and never reject.
- `Webhooks\Verifier::verify()` and the PSR-15 `Webhooks\WebhookHandler`, usable
  without DiscordPHP.

[Unreleased]: https://github.com/TheDiscordList/integration-discordphp/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/TheDiscordList/integration-discordphp/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/TheDiscordList/integration-discordphp/releases/tag/v0.1.0
