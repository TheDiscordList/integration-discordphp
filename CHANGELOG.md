# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/TheDiscordList/integration-discordphp/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/TheDiscordList/integration-discordphp/releases/tag/v0.1.0
