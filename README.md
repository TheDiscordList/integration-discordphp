# thediscordlist/integration-discordphp

Publish your [DiscordPHP](https://github.com/discord-php/DiscordPHP) bot's server
count, shard heartbeats and application commands to
[TheDiscordList](https://thediscordlist.com) automatically, and verify its
inbound webhooks.

No runtime dependencies of its own: the autoposter runs on the ReactPHP loop and
react/http client DiscordPHP already ships with, and the webhook verifier needs
nothing but PHP.

```sh
composer require thediscordlist/integration-discordphp
```

## Quickstart

```php
use Discord\Discord;
use Discord\WebSockets\Intents;
use TheDiscordList\DiscordPHP\Autoposter;

$discord = new Discord([
    'token' => getenv('DISCORD_TOKEN'),
    'intents' => Intents::getDefaultIntents(),
]);

Autoposter::attach($discord, getenv('TDL_TOKEN') ?: null);

$discord->run();
```

That is the whole integration, and it is also the recommended production setup.
It starts three publishers:

| Publisher | What it sends | Cadence |
| --- | --- | --- |
| Stats | `server_count`, `shard_count` | 5s after `init`, then every 5 min, debounced 20s on guild join/leave |
| Heartbeat | this shard's status, gateway latency and guild count | every ~30s, plus 1s after a gateway error or reconnect |
| Commands | your global application commands | once, 10s after `init` |

Your **stats token** is on the listing's manage page, under the API tab. It is
scoped to one listing, so it is the only credential this package needs.

`attach()` is safe before or after `$discord->run()`, including from inside an
`init` handler. Every request goes through react/http on DiscordPHP's own loop,
so nothing ever blocks the gateway. With a missing token it logs one warning
and returns an inert instance: a forgotten environment variable never takes
down a deploy.

## Sharding

DiscordPHP opens one gateway connection per `Discord` instance, so a sharded bot
runs one instance per shard, usually one per process. Each instance is its own
cluster with exactly one shard, read from the same options DiscordPHP identifies
with (`shard`, `shard_id` + `num_shards`, or `shardId` + `shardCount`):

| Layout | `server_count` comes from | Stats and commands posted by | Heartbeats |
| --- | --- | --- | --- |
| Single instance, no shard options | `$discord->guilds->count()` | this instance | cluster 0 of 1 |
| One instance per shard | your `serverCount` callback | shard 0 | every shard, as cluster *shard id* of *shard count* |
| Anything else | your `serverCount` callback | `cluster['id'] === 0` | as configured by `cluster` |

DiscordPHP has no cross-process bus, so a sharded bot has to say where the
bot-wide total lives. Without `serverCount`, shard 0 disables its stats
publisher with a clear log line rather than publishing one shard's guilds as
the total:

```php
Autoposter::attach($discord, getenv('TDL_TOKEN'), [
    'serverCount' => fn () => (int) $redis->get('bot:guilds'), // or a PromiseInterface<int>
]);
```

For a layout the shard options do not describe, name the cluster yourself:

```php
Autoposter::attach($discord, getenv('TDL_TOKEN'), [
    'cluster' => ['id' => (int) getenv('CLUSTER_ID'), 'count' => (int) getenv('CLUSTER_COUNT')],
    'serverCount' => fn () => $bus->sumGuildCounts(),
]);
```

Every instance heartbeats for **itself** on purpose. A manager reporting "all
fine" for a process whose host has died is exactly the failure the status page
exists to catch.

> **If the cluster count cannot be determined, heartbeats do not start.** The
> API hard-deletes every cluster row whose id is `>= cluster_count` in the
> request, so one process guessing `1` would erase all of its siblings and
> their monitoring history. A malformed shard option or a `cluster` override
> with a count of 0 (an unset environment variable) disables the heartbeat
> publisher with a `disabled` event naming the `cluster` option as the fix.
> Losing a status page beats losing data.

## Options

Pass an array with these keys, or the same names as named arguments to
`new Options(...)`. Durations are seconds, like ReactPHP timers.

```php
use TheDiscordList\DiscordPHP\Options;

Autoposter::attach($discord, $token, new Options(
    botId: '161660517914509312',            // default: the client's application id
    baseUrl: 'https://thediscordlist.com/api',

    stats: true,
    statsInterval: 300.0,
    statsDebounce: 20.0,
    statsMinGap: 60.0,

    heartbeat: true,
    heartbeatInterval: 30.0,                // starting cadence; retuned from the API response

    commands: true,
    postEmptyCommands: false,

    cluster: ['id' => 0, 'count' => 1],
    serverCount: fn () => 1234,
));
```

`statsMinGap` is a hard floor between two stats POSTs and is never below 60. A
join storm, a mass reconnect and a manual `postStats()` all route through it,
so the ceiling is structurally one request per minute.

`postEmptyCommands` is `false` because posting an empty list **clears** the
command list on your listing. Turn it on only if that is what you mean.

With `botId` set, the heartbeat starts before the gateway connects, so a
booting bot reads "connecting" on its status page instead of looking dead.

## Events and manual posting

`Autoposter` is an Evenement emitter, like `Discord` itself:

```php
use TheDiscordList\DiscordPHP\PublishException;
use TheDiscordList\DiscordPHP\PostResult;

$poster = Autoposter::attach($discord, getenv('TDL_TOKEN'));

$poster->on('posted', function (string $type, int $status) {});
$poster->on('error', function (PublishException $error) {}); // ->type, ->status, ->retryable
$poster->on('disabled', function (string $type, ?int $status, string $reason) {});

$poster->postStats()->then(fn (PostResult $result) => $result->ok); // ->status, ->skipped
$poster->postCommands(); // after re-registering commands in-process
$poster->postHeartbeat();

$poster->stop();
```

Nothing in this package throws at your bot. Timer callbacks and event handlers
are guarded, the `postX()` promises always resolve (never reject) with a
`PostResult`, and an `error` with no listener is logged through DiscordPHP's
logger instead. `disabled` is always logged, since it needs a human.

A `PostResult` with `ok === false` and a `skipped` reason (`unchanged`,
`min-gap`, `not-elected`, `suspended`, `plan`, `empty`, …) means nothing was
sent, which is usually correct rather than a failure.

An explicit `postX()` also re-arms a publisher that disabled itself, so you can
retry after fixing a token or a listing id without restarting. `stop()` cancels
every timer and detaches every listener; `$discord->close()` does the same.

## Webhooks

The `Webhooks` namespace never needs DiscordPHP, and needs ReactPHP only when
your callback returns a promise, so it works in any PHP application.

### Verifying by hand

```php
use TheDiscordList\DiscordPHP\Webhooks\Verifier;
use TheDiscordList\DiscordPHP\Webhooks\VerificationException;

try {
    $event = Verifier::verify($rawBody, $signatureHeader, $secret); // tolerance: 300s
} catch (VerificationException $error) {
    // $error->reason is an ErrorCode: MissingSignature, MalformedSignature,
    // StaleTimestamp, InvalidSignature or MalformedBody. Answer 401 for a
    // missing or invalid signature, 400 otherwise.
}
```

A blank `$secret` throws an `InvalidArgumentException`: nothing could ever
verify against it.

`$rawBody` must be the exact bytes that arrived. TheDiscordList signs the JSON
string it sends, so `json_encode($request->all())` escapes slashes and unicode
differently and can never verify: the signature covers bytes, not meaning.

### Laravel

In `routes/api.php`, which has no CSRF middleware:

```php
use Illuminate\Http\Request;
use TheDiscordList\DiscordPHP\Webhooks\Verifier;
use TheDiscordList\DiscordPHP\Webhooks\VerificationException;

Route::post('/webhooks/tdl', function (Request $request) {
    try {
        $event = Verifier::verify($request->getContent(), $request->header('X-TDL-Signature'), config('services.tdl.webhook_secret'));
    } catch (VerificationException) {
        return response()->noContent(400);
    }

    if (! Cache::add('tdl:'.$request->header('X-TDL-Delivery'), true, now()->addDay())) {
        return response()->noContent(); // a retry we already handled
    }

    ProcessTdlEvent::dispatch($event);

    return response()->noContent();
});
```

### PSR-15 (Slim, Mezzio, ReactPHP, …)

`WebhookHandler` is a PSR-15 `RequestHandlerInterface`. It needs the
interface-only packages `psr/http-server-handler` and `psr/http-factory`, plus
any PSR-17 factory:

```sh
composer require psr/http-server-handler psr/http-factory guzzlehttp/psr7
```

```php
use GuzzleHttp\Psr7\HttpFactory;
use TheDiscordList\DiscordPHP\Webhooks\WebhookHandler;

$handler = new WebhookHandler(
    getenv('TDL_WEBHOOK_SECRET'),
    function (array $event, array $context): void {
        // $context['delivery'] is X-TDL-Delivery, $context['attempt'] is 1-based.
    },
    new HttpFactory(),
);
```

It answers `204` once your callback returns, for every event name, `401` for a
missing or invalid signature, `400` for any other verification failure, `413`
for a body over 1 MiB, `405` for anything but POST, and `500` when your
callback throws so the delivery is retried. A blank secret throws at
construction. It is also invokable, so
`new React\Http\HttpServer($loop, $handler)` serves it on the bot's own loop.

Your callback may return a ReactPHP promise. Served by `HttpServer`, the
handler waits up to 8 seconds for it (`timeout:`): `204` when it fulfils, `500`
when it rejects, and `202` if it is still running, so the sender's 10-second
timeout is never hit. A synchronous PSR-15 `handle()` cannot wait and answers
`202` for any promise. A rejection always reaches `onError`.
`examples/bot.php` shows the same with `Verifier` alone and no extra packages.

### Events

| Event | Fires for | `data` |
| --- | --- | --- |
| `listing.bumped` | servers, bots | `points`, `bumped_at`, `next_bump_at`, `user` |
| `listing.bump_ready` | servers, bots | `ready_at` |
| `listing.upvoted` | servers, bots | `voted_at`, `total_upvotes`, `user` |
| `server.joined` | servers only | `joined_at`, `user` |
| `bot.added` | bots | `added_at`, `user` |
| `bot.stats_reported` | bots | `guild_count`, `shard_count`, `reported_at` |
| `webhook.ping` | both | `message` |

Every payload carries `id`, `event`, `created_at`, `listing` (`id`, `type`,
`name`, `url`) and `data`. `user` is `null` or `id`, `username`, `avatar`.

Two of them are not what their names suggest. `bot.added` is a **click** event,
dispatched on the install hand-off; Discord never tells anyone whether the
install completed. And `bot.stats_reported` is not an echo of your own POST: it
also fires when the owner edits the listing in the web UI, and on the
heartbeat's stats forward. `created_at` is
enqueue time, not send time, so never use it for freshness.

### Rules for a healthy endpoint

- **Always answer 2xx**, including for `webhook.ping` and for event names you
  do not recognise. The sender disables an endpoint after 10 consecutive failed
  deliveries. A 500 on an unknown event is a self-inflicted outage.
- A disabled endpoint is probed with a `webhook.ping` every 6 hours for 7 days,
  and **a 2xx to that probe re-enables it**.
- **Register an exact `https://` URL with no redirect.** The sender treats 3xx
  as a failure.
- **Deduplicate on `X-TDL-Delivery`.** It is stable across all retries of one
  delivery. A manual "redeliver" from the UI mints a new id on purpose.
- **Answer fast.** Queue slow work; PHP cannot answer early and keep going.
- **Rotating the secret has no grace window.** Deliveries already queued under
  the old secret become unverifiable.

## Plans, limits and known behaviour

- **Heartbeats need Gold or higher.** On a `403` the heartbeat publisher
  suspends and re-probes every 30 minutes rather than disabling: the tier is
  derived per request, so a bot can gain it mid-run.
- **Rate limits are shared.** The API counts stats and commands against one
  60/min budget keyed by client IP (heartbeats have their own 120/min). On a
  `429` this package suspends stats and commands for `Retry-After` (or until
  `X-RateLimit-Reset`) plus up to 2s of jitter, but keeps heartbeating: a stale
  number beats a false "down" on your status page. On shared hosting, other
  bots behind the same egress IP count against you.
- **`401` disables everything.** One log line, every publisher stops; fix the
  token and call any `postX()` to resume.
- **`404` never means "bad token".** It means the id is unknown or belongs to a
  server listing. That publisher disables itself; set `botId` if your
  application id differs from your bot's user id.
- **`409` on commands** means the listing is not set up for slash commands.
- `400`/`422` are logged once per status and not retried; the next run sends
  fresh data. `5xx`, timeouts and network errors are retried up to 3 attempts
  with backoff, then wait for the next run.
- An unchanged count is not re-posted until 30 minutes have passed: every stats
  post fires a `bot.stats_reported` webhook and the server does not dedupe.
- DiscordPHP evicts a guild from its cache when it goes unavailable during a
  Discord outage. This package keeps counting those guilds until they return,
  so an outage does not publish a dip.
- DiscordPHP does not emit an event when the gateway socket drops without an
  error. A shard whose gateway heartbeats stop for 2 minutes is reported as
  `connecting` until it acks again.
- Without `Intents::GUILDS` DiscordPHP never fills its guild cache, so the
  count is wrong. The package warns once.

## Requirements

- PHP `>= 8.1`
- DiscordPHP (`team-reflex/discord-php`) `^10.1` for `Autoposter` (10.1 is the
  first v10 release on react/promise 3); not needed for `Webhooks\Verifier`

Working on this repository needs PHP `>= 8.2` (PHPUnit 11). CI tests 8.2, 8.3
and 8.4 and lints the sources on 8.1.

```sh
composer install
composer test      # PHPUnit
composer analyse   # PHPStan, level 8
composer lint      # Pint
```

## Publishing (maintainers)

Packagist builds releases from git tags; there is no upload step and no
registry token in this repository.

One-time setup:

1. Sign in to [packagist.org](https://packagist.org) with the GitHub account
   that administers `TheDiscordList/integration-discordphp`, preferably as a
   member of a `thediscordlist` Packagist maintainer group.
2. **Submit** `https://github.com/TheDiscordList/integration-discordphp`. The
   package name comes from `composer.json`.
3. Under your Packagist profile, connect GitHub (or install the Packagist GitHub
   app for the organisation) so the repository gets the Packagist hook and every
   push and tag updates the package automatically. The package page shows a
   warning while it is not auto-updated.

Each release:

1. Move the `Unreleased` notes in `CHANGELOG.md` under the new version and bump
   `Autoposter::VERSION` (it is sent in the `User-Agent`; a test keeps the two in
   step).
2. Merge to `main` with CI green.
3. `git tag v0.2.0 && git push origin v0.2.0`. The release workflow checks the
   tag against `Autoposter::VERSION`, runs the suite and creates the GitHub
   release; Packagist picks the tag up through the hook within a minute.

## License

MIT
