<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Publishers;

use Closure;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use TheDiscordList\DiscordPHP\Http\Outcome;
use TheDiscordList\DiscordPHP\PostResult;
use TheDiscordList\DiscordPHP\PublishException;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Shared state machine. Every public path resolves a PostResult and never
 * rejects, and every timer callback is guarded: a 502 must never reach the bot.
 *
 * @internal
 */
abstract class Publisher
{
    public const PLAN_REPROBE = 1800.0;

    private const MAX_ATTEMPTS = 3;

    protected ?string $disabledReason = null;

    protected float $suspendedUntil = 0.0;

    protected bool $started = false;

    protected bool $stopped = false;

    private ?TimerInterface $cadence = null;

    private ?TimerInterface $pending = null;

    /** @var array<int, true> */
    private array $reported = [];

    public function __construct(protected readonly Context $ctx) {}

    /** @return 'stats'|'heartbeat'|'commands' */
    abstract public function kind(): string;

    abstract public function start(): void;

    /** @return PromiseInterface<PostResult> */
    abstract public function post(): PromiseInterface;

    /** (Re)starts the cadence, if the publisher has one. */
    abstract protected function resume(): void;

    public function stop(): void
    {
        $this->stopped = true;
        $this->cancelTimers();
    }

    /** Emits `disabled` once and stops the timers. A manual post re-arms it. */
    public function disable(?int $status, string $reason, string $message, bool $quiet = false): void
    {
        $this->cancelTimers();
        if ($this->disabledReason !== null) {
            return;
        }
        $this->disabledReason = $reason;
        if (! $quiet) {
            ($this->ctx->emit)('disabled', [$this->kind(), $status, $reason], $message);
        }
    }

    protected function rearm(): void
    {
        if ($this->disabledReason === null) {
            return;
        }
        $this->disabledReason = null;
        if ($this->started && ! $this->stopped) {
            $this->resume();
        }
    }

    /** @param Closure(): (PromiseInterface<PostResult>|PostResult) $run */
    protected function every(float $seconds, Closure $run): void
    {
        if ($this->cadence !== null) {
            $this->ctx->loop->cancelTimer($this->cadence);
        }
        $this->cadence = $this->ctx->loop->addPeriodicTimer($seconds, fn () => $this->guard($run));
    }

    /**
     * One pending one-shot per publisher; scheduling another replaces it, so
     * bursts of guild or shard events coalesce.
     *
     * @param  Closure(): (PromiseInterface<PostResult>|PostResult)  $run
     */
    protected function after(float $seconds, Closure $run): void
    {
        if ($this->pending !== null) {
            $this->ctx->loop->cancelTimer($this->pending);
        }
        $this->pending = $this->ctx->loop->addTimer(max($seconds, 0.0), function () use ($run): void {
            $this->pending = null;
            $this->guard($run);
        });
    }

    /**
     * @param  Closure(): (PromiseInterface<PostResult>|PostResult)  $work
     * @return PromiseInterface<PostResult>
     */
    protected function guard(Closure $work): PromiseInterface
    {
        try {
            $promise = resolve($work());
        } catch (Throwable $error) {
            $promise = reject($error);
        }

        return $promise->then(null, function (Throwable $error): PostResult {
            $this->unexpected($error);

            return PostResult::skipped('error');
        });
    }

    /**
     * Sends one request, retrying transient failures with backoff. A retry
     * that would outlast the next scheduled run is dropped: that run carries
     * fresher data, and a failed request still spends rate-limit budget.
     *
     * @param  array<string, mixed>  $body
     * @return PromiseInterface<PostResult>
     */
    protected function send(string $path, array $body, bool $heartbeat, ?float $nextRunIn = null, int $attempt = 0): PromiseInterface
    {
        if ($attempt > 0 && $this->stopped) {
            return resolve(PostResult::skipped('stopped'));
        }

        return $this->ctx->api->post($path, $body, $heartbeat)->then(
            function (Outcome $outcome) use ($path, $body, $heartbeat, $nextRunIn, $attempt): PromiseInterface|PostResult {
                if ($outcome->kind === Outcome::TRANSIENT && $attempt < self::MAX_ATTEMPTS - 1) {
                    $delay = self::backoff($attempt);
                    if ($nextRunIn === null || $delay < $nextRunIn) {
                        return $this->sleep($delay)->then(fn () => $this->send($path, $body, $heartbeat, $nextRunIn, $attempt + 1));
                    }
                }

                return $this->react($outcome);
            },
        );
    }

    protected function react(Outcome $outcome): PostResult
    {
        $kind = $this->kind();

        switch ($outcome->kind) {
            case Outcome::OK:
                ($this->ctx->emit)('posted', [$kind, $outcome->status], '');

                return new PostResult(true, $outcome->status);

            case Outcome::DROPPED:
                return PostResult::skipped('suspended');

            case Outcome::THROTTLED:
                return new PostResult(false, 429, 'throttled');

            case Outcome::PAYLOAD:
                // Stays enabled: the next run may carry valid data.
                $this->reportOnce($outcome->status ?? 0, "TheDiscordList rejected the {$kind} payload ({$outcome->status}): {$outcome->message}");

                return new PostResult(false, $outcome->status);

            case Outcome::AUTH:
                $this->disable(401, 'unauthorized', 'TheDiscordList rejected the stats token (401). Every publisher is disabled; check the token on the listing\'s API tab.');
                ($this->ctx->onUnauthorized)();

                return new PostResult(false, 401);

            case Outcome::PLAN:
                // Not a disable: the plan is derived per request, so a bot can gain it mid-run.
                $this->suspendedUntil = $this->ctx->now() + self::PLAN_REPROBE;
                $this->reportOnce(403, "The {$kind} endpoint needs a higher plan (Gold for heartbeats). Re-probing every 30 minutes.");

                return new PostResult(false, 403, 'plan');

            case Outcome::CONFIG:
                $this->disable(
                    $outcome->status,
                    $outcome->status === 409 ? 'conflict' : 'not-found',
                    $outcome->status === 409
                        ? "TheDiscordList refused the {$kind} post (409): this listing is not set up for slash commands. The {$kind} publisher is disabled."
                        : "TheDiscordList does not know bot id {$this->ctx->botId()} (404), or it belongs to a server listing. Set the botId option to your Discord application id. The {$kind} publisher is disabled.",
                );

                return new PostResult(false, $outcome->status);

            default:
                $this->emitError($outcome->message, $outcome->status, true);

                return new PostResult(false, $outcome->status);
        }
    }

    protected function unexpected(Throwable $error): void
    {
        $this->emitError($error->getMessage(), null, false, $error);
    }

    /** Full jitter in the upper half: min(2^attempt, 30) seconds × [0.5, 1.0]. */
    private static function backoff(int $attempt): float
    {
        return min(2 ** $attempt, 30) * (0.5 + mt_rand() / mt_getrandmax() * 0.5);
    }

    /** @return PromiseInterface<null> */
    private function sleep(float $seconds): PromiseInterface
    {
        $deferred = new Deferred;
        $this->ctx->loop->addTimer($seconds, fn () => $deferred->resolve(null));

        return $deferred->promise();
    }

    private function reportOnce(int $status, string $message): void
    {
        if (isset($this->reported[$status])) {
            return;
        }
        $this->reported[$status] = true;
        $this->emitError($message, $status, false);
    }

    private function emitError(string $message, ?int $status, bool $retryable, ?Throwable $previous = null): void
    {
        $message = "{$this->kind()}: {$message}";
        ($this->ctx->emit)('error', [new PublishException($message, $this->kind(), $status, $retryable, $previous)], $message);
    }

    private function cancelTimers(): void
    {
        foreach ([$this->cadence, $this->pending] as $timer) {
            if ($timer !== null) {
                $this->ctx->loop->cancelTimer($timer);
            }
        }
        $this->cadence = null;
        $this->pending = null;
    }
}
