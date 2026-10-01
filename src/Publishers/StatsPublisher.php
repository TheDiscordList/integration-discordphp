<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Publishers;

use Closure;
use React\Promise\PromiseInterface;
use TheDiscordList\DiscordPHP\PostResult;
use Throwable;

use function React\Promise\resolve;

/**
 * @internal
 */
final class StatsPublisher extends Publisher
{
    public const FIRST_POST_DELAY = 5.0;

    public const MIN_GAP_FLOOR = 60.0;

    /** Republish an unchanged count this often, so a listing never looks abandoned. */
    public const FORCE_AFTER = 1800.0;

    private readonly float $interval;

    private readonly float $debounce;

    private readonly float $minGap;

    private float $lastPostAt = 0.0;

    private ?int $lastCount = null;

    private ?int $lastShardCount = null;

    private float $debounceCap = 0.0;

    private bool $inFlight = false;

    /**
     * @param  ?Closure(): (int|PromiseInterface<int>)  $serverCount
     */
    public function __construct(
        Context $ctx,
        private readonly ?Closure $serverCount,
        float $interval,
        float $debounce,
        float $minGap,
    ) {
        parent::__construct($ctx);
        $this->minGap = max($minGap, self::MIN_GAP_FLOOR);
        $this->interval = max($interval, $this->minGap);
        $this->debounce = max($debounce, 0.0);
    }

    public function kind(): string
    {
        return 'stats';
    }

    public function start(): void
    {
        if ($this->started || $this->stopped) {
            return;
        }
        $this->started = true;

        if (! $this->ctx->topology->isStatsPublisher()) {
            return;
        }
        if (! $this->measurable()) {
            $this->disable(null, 'server-count-unknown', 'This process holds one shard of several, so its guild cache is not the bot-wide total, and DiscordPHP has no cross-process bus to sum it. Pass the serverCount option to publish stats from shard 0. The stats publisher is disabled.');

            return;
        }

        $this->after(self::FIRST_POST_DELAY, fn () => $this->publish(false));
        $this->resume();
    }

    /** Trailing debounce, hard-capped so a join storm cannot starve the post. */
    public function onGuildChange(): void
    {
        if (! $this->started || $this->stopped || $this->disabledReason !== null || ! $this->ctx->topology->isStatsPublisher()) {
            return;
        }
        $now = $this->ctx->now();
        if ($this->debounceCap === 0.0) {
            $this->debounceCap = $now + $this->minGap;
        }
        $this->after(min($now + $this->debounce, $this->debounceCap) - $now, fn () => $this->publish(false));
    }

    public function post(): PromiseInterface
    {
        return $this->guard(function (): PromiseInterface|PostResult {
            if ($this->stopped) {
                return PostResult::skipped('stopped');
            }
            if (! $this->ctx->topology->isStatsPublisher()) {
                return PostResult::skipped('not-elected');
            }
            if (! $this->measurable()) {
                return PostResult::skipped('server-count-unknown');
            }
            $this->rearm();

            return $this->publish(true);
        });
    }

    protected function resume(): void
    {
        $this->every($this->interval, fn () => $this->publish(false));
    }

    /** A local guild cache is the bot-wide total only when this process holds every shard. */
    private function measurable(): bool
    {
        return $this->serverCount !== null || $this->ctx->topology->clusterCount === 1;
    }

    /** @return PromiseInterface<PostResult>|PostResult */
    private function publish(bool $manual): PromiseInterface|PostResult
    {
        $this->debounceCap = 0.0;
        if ($this->stopped) {
            return PostResult::skipped('stopped');
        }
        if (! $manual && $this->disabledReason !== null) {
            return PostResult::skipped('disabled');
        }
        if ($this->inFlight) {
            return PostResult::skipped('in-flight');
        }

        $now = $this->ctx->now();
        if (! $manual && $now < $this->suspendedUntil) {
            return PostResult::skipped('plan');
        }
        // A hard floor: a join storm, a reconnect and a manual post all route
        // through it, so the ceiling is structurally one request per minGap.
        if ($this->lastPostAt > 0 && $now < $this->lastPostAt + $this->minGap) {
            $this->after($this->lastPostAt + $this->minGap - $now, fn () => $this->publish(false));

            return PostResult::skipped('min-gap');
        }

        $botId = $this->ctx->botId();
        if ($botId === null) {
            return PostResult::skipped('no-bot-id');
        }

        $this->inFlight = true;

        return $this->measure()
            ->then(function (?int $count) use ($botId): PromiseInterface|PostResult {
                if ($count === null) {
                    return PostResult::skipped('unmeasurable');
                }
                $shardCount = $this->ctx->topology->shardCount;
                // Every stats post re-indexes the listing and fires a bot.stats_reported
                // webhook even when nothing moved, and the server does not dedupe.
                if ($count === $this->lastCount && $shardCount === $this->lastShardCount
                    && $this->ctx->now() < $this->lastPostAt + self::FORCE_AFTER) {
                    return PostResult::skipped('unchanged');
                }

                $this->lastPostAt = $this->ctx->now();

                // shard_count is always sent: the server stores `shard_count ?? null`.
                return $this->send("/bots/{$botId}/stats", ['server_count' => $count, 'shard_count' => $shardCount], false, $this->interval)
                    ->then(function (PostResult $result) use ($count, $shardCount): PostResult {
                        if ($result->ok) {
                            $this->lastCount = $count;
                            $this->lastShardCount = $shardCount;
                        }

                        return $result;
                    });
            })
            ->finally(function (): void {
                $this->inFlight = false;
            });
    }

    /** @return PromiseInterface<?int> */
    private function measure(): PromiseInterface
    {
        if ($this->serverCount === null) {
            return resolve($this->ctx->guildCount());
        }

        try {
            $value = ($this->serverCount)();
        } catch (Throwable $error) {
            $this->unexpected($error);

            return resolve(null);
        }

        return resolve($value)->then(
            fn (mixed $count): ?int => is_int($count) && $count >= 0 ? $count : null,
            function (Throwable $error): ?int {
                $this->unexpected($error);

                return null;
            },
        );
    }
}
