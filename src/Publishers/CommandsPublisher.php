<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Publishers;

use React\Promise\PromiseInterface;
use RuntimeException;
use TheDiscordList\DiscordPHP\PostResult;
use Throwable;

/**
 * Posts the global application commands once, shortly after ready. Commands
 * change on deploy and a deploy is a restart; `postCommands()` covers the rest.
 *
 * @internal
 */
final class CommandsPublisher extends Publisher
{
    /** Offset from the first stats post: the two share one rate-limit budget. */
    public const DELAY = 10.0;

    public function __construct(Context $ctx, private readonly bool $postEmpty)
    {
        parent::__construct($ctx);
    }

    public function kind(): string
    {
        return 'commands';
    }

    public function start(): void
    {
        if ($this->started || $this->stopped) {
            return;
        }
        $this->started = true;

        // Global commands are identical on every shard.
        if ($this->ctx->topology->isStatsPublisher()) {
            $this->after(self::DELAY, fn () => $this->publish());
        }
    }

    public function post(): PromiseInterface
    {
        return $this->guard(function (): PromiseInterface|PostResult {
            if ($this->stopped) {
                return PostResult::skipped('stopped');
            }
            $this->rearm();

            return $this->publish();
        });
    }

    protected function resume(): void {}

    /** @return PromiseInterface<PostResult>|PostResult */
    private function publish(): PromiseInterface|PostResult
    {
        if ($this->stopped) {
            return PostResult::skipped('stopped');
        }
        if ($this->disabledReason !== null) {
            return PostResult::skipped('disabled');
        }
        if ($this->ctx->now() < $this->suspendedUntil) {
            return PostResult::skipped('plan');
        }
        $botId = $this->ctx->botId();
        $application = $this->ctx->discord->application;
        if ($botId === null || ! is_object($application) || ! is_scalar($application->id) || $application->id === '') {
            $this->unexpected(new RuntimeException('DiscordPHP has not loaded the application yet; commands were not posted.'));

            return PostResult::skipped('fetch-failed');
        }

        return $application->commands->freshen()->then(
            function (mixed $repository) use ($botId): PromiseInterface|PostResult {
                // Raw attributes are Discord's own snake_case JSON, forwarded verbatim.
                // Anything odd aborts: a partial list would delete commands from the listing.
                $unexpected = new RuntimeException('DiscordPHP returned an unexpected command list; commands were not posted.');
                if (! is_iterable($repository)) {
                    throw $unexpected;
                }
                $commands = [];
                foreach ($repository as $command) {
                    if (! is_object($command) || ! method_exists($command, 'getRawAttributes')) {
                        throw $unexpected;
                    }
                    $commands[] = $command->getRawAttributes();
                }

                // An empty array CLEARS the listing's commands, so it is never sent by accident.
                if ($commands === [] && ! $this->postEmpty) {
                    return PostResult::skipped('empty');
                }

                return $this->send("/bots/{$botId}/commands", ['commands' => $commands], false);
            },
            function (Throwable $error): PostResult {
                $this->unexpected($error);

                return PostResult::skipped('fetch-failed');
            },
        );
    }
}
