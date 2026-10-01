<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Tests\Support;

use ArrayObject;
use Discord\Discord;
use Discord\WebSockets\Intents;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use stdClass;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * A real Discord object with the gateway left out: the parent constructor
 * would connect, so it is skipped and the client part is a plain object.
 */
final class FakeDiscord extends Discord
{
    /** @var ArrayObject<string, true> */
    public ArrayObject $guildCache;

    /** @var list<array<string, mixed>> */
    public array $commandList = [];

    public ?Throwable $commandsError = null;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(LoopInterface $loop, LoggerInterface $logger, array $options = [], int $guilds = 3)
    {
        $this->loop = $loop;
        $this->logger = $logger;
        $this->options = $options + ['intents' => Intents::getDefaultIntents()];
        $this->guildCache = new ArrayObject;
        for ($i = 1; $i <= $guilds; $i++) {
            $this->guildCache["g{$i}"] = true;
        }

        $repository = new class($this)
        {
            public function __construct(private readonly FakeDiscord $discord) {}

            public function freshen(): PromiseInterface
            {
                if ($this->discord->commandsError !== null) {
                    return reject($this->discord->commandsError);
                }

                return resolve(array_map(fn (array $attributes): object => new class($attributes)
                {
                    /** @param array<string, mixed> $attributes */
                    public function __construct(private readonly array $attributes) {}

                    /** @return array<string, mixed> */
                    public function getRawAttributes(): array
                    {
                        return $this->attributes;
                    }
                }, $this->discord->commandList));
            }
        };

        $application = new stdClass;
        $application->id = '42';
        $application->commands = $repository;

        $client = new stdClass;
        $client->id = '42';
        $client->guilds = $this->guildCache;
        $client->application = $application;
        $this->client = $client;
    }

    public function fireInit(): void
    {
        $this->emittedInit = true;
        $this->emit('init', [$this]);
    }

    public function markInitialised(): void
    {
        $this->emittedInit = true;
    }
}
