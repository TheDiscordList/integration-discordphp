<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Publishers;

use React\Promise\PromiseInterface;
use TheDiscordList\DiscordPHP\Http\Outcome;
use TheDiscordList\DiscordPHP\PostResult;

/**
 * One heartbeat per `Discord` instance, for itself. A manager reporting "all
 * fine" for a process that has died is exactly what the status page catches.
 *
 * @internal
 */
final class HeartbeatPublisher extends Publisher
{
    public const MIN_INTERVAL = 15.0;

    public const MAX_INTERVAL = 60.0;

    /** Added to every interval, so a fleet restarted together drifts apart. */
    public const JITTER = 5.0;

    /** An out-of-band beat waits until at least this long after the previous one. */
    public const SOON_GAP = 10.0;

    /** The API caps `latency_ms` here. */
    public const MAX_LATENCY_MS = 600000;

    /**
     * DiscordPHP emits nothing when the socket drops without an error, but it
     * stops heartbeating the gateway. Two missed gateway beats (~41s each) mean
     * the shard is not really ready, whatever the last event said.
     */
    public const STALE_AFTER = 120.0;

    private float $interval;

    private bool $inFlight = false;

    private bool $ready = false;

    private float $lastSeen = 0.0;

    private float $lastBeatAt = -INF;

    private ?int $latency = null;

    public function __construct(Context $ctx, float $interval)
    {
        parent::__construct($ctx);
        $this->interval = self::clamp($interval);
    }

    public function kind(): string
    {
        return 'heartbeat';
    }

    public function start(): void
    {
        if ($this->started || $this->stopped) {
            return;
        }
        $this->started = true;

        // Every beat hard-deletes the listing's cluster rows numbered >= cluster_count,
        // history included. A guessed count could erase every sibling, so unknown means silent.
        if ($this->ctx->topology->clusterCount === null) {
            $this->disable(null, 'cluster-count-unknown', "Could not determine how many clusters this bot runs, so heartbeats are disabled: a wrong cluster_count deletes sibling clusters server-side. Pass the cluster option, ['id' => …, 'count' => …], to enable them.");

            return;
        }

        $this->after(0.0, fn () => $this->beat(false));
    }

    public function post(): PromiseInterface
    {
        return $this->guard(function (): PromiseInterface|PostResult {
            if ($this->stopped) {
                return PostResult::skipped('stopped');
            }
            if ($this->ctx->topology->clusterCount === null) {
                return PostResult::skipped('cluster-count-unknown');
            }
            $this->rearm();

            return $this->beat(true);
        });
    }

    public function onReady(): void
    {
        $this->ready = true;
        $this->lastSeen = $this->ctx->now();
        $this->beatNow();
    }

    public function onAck(float $milliseconds): void
    {
        $this->latency = min(max((int) round($milliseconds), 0), self::MAX_LATENCY_MS);
        $this->lastSeen = $this->ctx->now();
    }

    public function onConnectionLost(): void
    {
        $this->ready = false;
        $this->beatNow();
    }

    protected function resume(): void
    {
        $this->next($this->ctx->now());
    }

    /**
     * The cadence follows `next_beat_within_seconds`, so a change to the
     * server's grace window retunes existing installs without a release.
     */
    protected function react(Outcome $outcome): PostResult
    {
        $seconds = $outcome->kind === Outcome::OK ? ($outcome->body['next_beat_within_seconds'] ?? null) : null;
        if ((is_int($seconds) || is_float($seconds)) && $seconds > 0) {
            $this->interval = self::clamp(round($seconds / 3));
        }

        return parent::react($outcome);
    }

    /** Coalesced, so a reconnect storm sends one beat. */
    private function beatNow(): void
    {
        if ($this->started && ! $this->stopped && $this->disabledReason === null) {
            $this->after(max(1.0, self::SOON_GAP - ($this->ctx->now() - $this->lastBeatAt)), fn () => $this->beat(false));
        }
    }

    /** One-shot timers, each armed when the previous beat settles and never inside a plan suspension. */
    private function next(float $startedAt): void
    {
        if (! $this->started || $this->stopped || $this->disabledReason !== null) {
            return;
        }
        $now = $this->ctx->now();
        $delay = $this->interval + mt_rand() / mt_getrandmax() * self::JITTER - ($now - $startedAt);
        $this->after(max($delay, 1.0, $this->suspendedUntil - $now), fn () => $this->beat(false));
    }

    /** @return PromiseInterface<PostResult>|PostResult */
    private function beat(bool $manual): PromiseInterface|PostResult
    {
        if ($this->stopped) {
            return PostResult::skipped('stopped');
        }
        if (! $manual && $this->disabledReason !== null) {
            return PostResult::skipped('disabled');
        }
        if ($this->inFlight) {
            return PostResult::skipped('in-flight');
        }
        $clusterCount = $this->ctx->topology->clusterCount;
        if ($clusterCount === null) {
            return PostResult::skipped('cluster-count-unknown');
        }
        if (! $manual && $this->ctx->now() < $this->suspendedUntil) {
            $this->next($this->ctx->now());

            return PostResult::skipped('plan');
        }
        $botId = $this->ctx->botId();
        if ($botId === null) {
            $this->next($this->ctx->now());

            return PostResult::skipped('no-bot-id');
        }

        $shard = ['id' => $this->ctx->topology->shardId, 'status' => $this->status()];
        if ($this->latency !== null) {
            $shard['latency_ms'] = $this->latency;
        }
        // Before ready the cache is still filling; an absent count beats a false 0.
        if ($this->ready) {
            $shard['guilds'] = $this->ctx->guildCount();
        }

        $this->inFlight = true;
        $startedAt = $this->lastBeatAt = $this->ctx->now();

        return $this->send("/bots/{$botId}/heartbeat", [
            'cluster' => $this->ctx->topology->clusterId,
            'cluster_count' => $clusterCount,
            'shards' => [$shard],
        ], true, $this->interval)->finally(function () use ($startedAt): void {
            $this->inFlight = false;
            $this->next($startedAt);
        });
    }

    /** @return 'ready'|'connecting' */
    private function status(): string
    {
        return $this->ready && $this->ctx->now() - $this->lastSeen <= self::STALE_AFTER ? 'ready' : 'connecting';
    }

    private static function clamp(float $seconds): float
    {
        return min(max($seconds, self::MIN_INTERVAL), self::MAX_INTERVAL);
    }
}
