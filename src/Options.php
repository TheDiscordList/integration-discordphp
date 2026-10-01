<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP;

use Closure;
use Error;
use InvalidArgumentException;
use React\Promise\PromiseInterface;

/**
 * Autoposter configuration. Durations are seconds, like ReactPHP timers.
 */
final class Options
{
    /**
     * @param  ?string  $botId  Discord application id. Defaults to the client's application id.
     * @param  float  $statsMinGap  Hard floor between two stats POSTs, manual ones included. Never below 60.
     * @param  bool  $postEmptyCommands  An empty list CLEARS the listing's commands, so it is not sent unless set.
     * @param  ?array{id: int, count: int}  $cluster  This process's cluster, for layouts that cannot be read from the shard options.
     * @param  ?Closure(): (int|PromiseInterface<int>)  $serverCount  The bot-wide guild total, for multi-process bots.
     */
    public function __construct(
        public readonly ?string $botId = null,
        public readonly string $baseUrl = Autoposter::DEFAULT_BASE_URL,
        public readonly bool $stats = true,
        public readonly float $statsInterval = 300.0,
        public readonly float $statsDebounce = 20.0,
        public readonly float $statsMinGap = 60.0,
        public readonly bool $heartbeat = true,
        public readonly float $heartbeatInterval = 30.0,
        public readonly bool $commands = true,
        public readonly bool $postEmptyCommands = false,
        public readonly ?array $cluster = null,
        public readonly ?Closure $serverCount = null,
    ) {}

    /**
     * Same keys as the constructor's named arguments.
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        try {
            return new self(...$options);
        } catch (Error $error) {
            throw new InvalidArgumentException('Invalid TheDiscordList option: '.$error->getMessage(), 0, $error);
        }
    }
}
