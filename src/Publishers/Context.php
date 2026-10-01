<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Publishers;

use Closure;
use Countable;
use Discord\Discord;
use React\EventLoop\LoopInterface;
use TheDiscordList\DiscordPHP\Http\ApiClient;
use TheDiscordList\DiscordPHP\Topology;

/**
 * What the three publishers share.
 *
 * @internal
 */
final class Context
{
    /**
     * Guild ids DiscordPHP evicted as unavailable. Unlike discord.js it drops a
     * guild from its cache during an outage, so these are added back rather than
     * publishing a dip that is not real.
     *
     * @var array<string, true>
     */
    public array $unavailable = [];

    /**
     * @param  Closure(): float  $clock  Unix seconds.
     * @param  Closure(string, list<mixed>, string): void  $emit  Event name, listener arguments, log line.
     * @param  Closure(): void  $onUnauthorized
     */
    public function __construct(
        public readonly Discord $discord,
        public readonly LoopInterface $loop,
        public readonly ApiClient $api,
        public readonly Topology $topology,
        private readonly ?string $botId,
        private readonly Closure $clock,
        public readonly Closure $emit,
        public readonly Closure $onUnauthorized,
    ) {}

    public function now(): float
    {
        return ($this->clock)();
    }

    /** The configured id, else the application id, else the bot user id (equal for every modern bot). */
    public function botId(): ?string
    {
        if ($this->botId !== null && $this->botId !== '') {
            return $this->botId;
        }

        // Never isset()/?? on $discord: it has __get but no __isset, so both always miss.
        $application = $this->discord->application;
        $id = is_object($application) ? $application->id : null;
        if (! is_scalar($id) || $id === '') {
            $id = $this->discord->id;
        }

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    public function guildCount(): int
    {
        $guilds = $this->discord->guilds;

        return ($guilds instanceof Countable ? $guilds->count() : 0) + count($this->unavailable);
    }
}
