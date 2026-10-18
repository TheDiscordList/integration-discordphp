<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP;

use Closure;
use Discord\Discord;
use Discord\Parts\Guild\Guild;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;
use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use React\Promise\PromiseInterface;
use stdClass;
use TheDiscordList\DiscordPHP\Http\ApiClient;
use TheDiscordList\DiscordPHP\Http\BrowserTransport;
use TheDiscordList\DiscordPHP\Http\Transport;
use TheDiscordList\DiscordPHP\Publishers\CommandsPublisher;
use TheDiscordList\DiscordPHP\Publishers\Context;
use TheDiscordList\DiscordPHP\Publishers\HeartbeatPublisher;
use TheDiscordList\DiscordPHP\Publishers\StatsPublisher;
use Throwable;

use function React\Promise\resolve;

/**
 * Keeps a TheDiscordList listing in step with a DiscordPHP bot: server count,
 * shard heartbeats and application commands, all on DiscordPHP's own loop.
 *
 * Events: `posted` (string $type, int $status), `error` (PublishException $error),
 * `disabled` (string $type, ?int $status, string $reason).
 */
final class Autoposter implements EventEmitterInterface
{
    use EventEmitterTrait;

    public const VERSION = '0.1.0';

    public const DEFAULT_BASE_URL = 'https://thediscordlist.com/api';

    public const USER_AGENT = 'TheDiscordList-discordphp/'.self::VERSION.' (+https://github.com/TheDiscordList/integration-discordphp)';

    private const LOG_PREFIX = '[TheDiscordList] ';

    private ?StatsPublisher $stats = null;

    private ?HeartbeatPublisher $heartbeat = null;

    private ?CommandsPublisher $commands = null;

    /** @var list<array{string, Closure}> */
    private array $wired = [];

    private bool $stopped = false;

    private function __construct(private readonly Discord $discord) {}

    /**
     * Wires a DiscordPHP client to a TheDiscordList listing. Safe before or
     * after `$discord->run()`. With an empty token it logs one warning and
     * returns an inert instance: a missing environment variable must never
     * take down a deploy.
     *
     * @param  array<string, mixed>|Options  $options
     */
    public static function attach(Discord $discord, ?string $token, array|Options $options = []): self
    {
        $options = $options instanceof Options ? $options : Options::fromArray($options);

        if ($token === null || trim($token) === '') {
            $discord->getLogger()->warning(self::LOG_PREFIX.'No stats token supplied; auto-posting is disabled.');

            return new self($discord);
        }

        return self::create($discord, $token, $options, new BrowserTransport($discord->getLoop()), static fn (): float => hrtime(true) / 1e9);
    }

    /**
     * @internal Takes the transport and clock that `attach()` defaults.
     *
     * @param  Closure(): float  $clock  Monotonic seconds.
     */
    public static function create(Discord $discord, string $token, Options $options, Transport $transport, Closure $clock): self
    {
        $poster = new self($discord);
        $poster->wire($token, $options, $transport, $clock);

        return $poster;
    }

    /** @return PromiseInterface<PostResult> Never rejects. */
    public function postStats(): PromiseInterface
    {
        return $this->stats?->post() ?? resolve(PostResult::skipped('disabled'));
    }

    /** @return PromiseInterface<PostResult> Never rejects. */
    public function postHeartbeat(): PromiseInterface
    {
        return $this->heartbeat?->post() ?? resolve(PostResult::skipped('disabled'));
    }

    /** @return PromiseInterface<PostResult> Never rejects. */
    public function postCommands(): PromiseInterface
    {
        return $this->commands?->post() ?? resolve(PostResult::skipped('disabled'));
    }

    /** Cancels every timer and detaches every client listener. Not restartable. */
    public function stop(): void
    {
        $this->stopped = true;
        $this->stats?->stop();
        $this->heartbeat?->stop();
        $this->commands?->stop();
        foreach ($this->wired as [$event, $listener]) {
            $this->discord->removeListener($event, $listener);
        }
        $this->wired = [];
    }

    /** @param Closure(): float $clock */
    private function wire(string $token, Options $options, Transport $transport, Closure $clock): void
    {
        [$discordOptions, $initialised] = self::inspect($this->discord);
        $topology = Topology::detect($discordOptions, $options->cluster);

        $ctx = new Context(
            $this->discord,
            $this->discord->getLoop(),
            new ApiClient($transport, $options->baseUrl, $token, self::USER_AGENT, $clock),
            $topology,
            $options->botId,
            $clock,
            $this->relay(...),
            $this->unauthorized(...),
        );

        if ($options->stats) {
            $this->stats = new StatsPublisher($ctx, $options->serverCount, $options->statsInterval, $options->statsDebounce, $options->statsMinGap);
        }
        if ($options->heartbeat) {
            $this->heartbeat = new HeartbeatPublisher($ctx, $options->heartbeatInterval);
        }
        if ($options->commands) {
            $this->commands = new CommandsPublisher($ctx, $options->postEmptyCommands);
        }

        $this->listen(Event::GUILD_CREATE, function (mixed $guild = null) use ($ctx): void {
            $id = self::guildId($guild);
            if ($id !== null && isset($ctx->unavailable[$id])) {
                unset($ctx->unavailable[$id]);

                return;
            }
            $this->stats?->onGuildChange();
        });
        $this->listen(Event::GUILD_DELETE, function (mixed $guild = null, mixed $client = null, mixed $unavailable = false) use ($ctx): void {
            $id = self::guildId($guild);
            if ($unavailable === true) {
                if ($id !== null) {
                    $ctx->unavailable[$id] = true;
                }

                return;
            }
            if ($id !== null) {
                unset($ctx->unavailable[$id]);
            }
            $this->stats?->onGuildChange();
        });
        $this->listen('heartbeat-ack', function (mixed $milliseconds = null): void {
            if (is_int($milliseconds) || is_float($milliseconds)) {
                $this->heartbeat?->onAck((float) $milliseconds);
            }
        });
        $this->listen('reconnected', fn () => $this->heartbeat?->onReady());
        $this->listen('error', fn () => $this->heartbeat?->onConnectionLost());
        $this->listen('closed', fn () => $this->stop());

        $logger = $this->discord->getLogger();
        $intents = $discordOptions['intents'] ?? null;
        if (is_int($intents) && ($intents & Intents::GUILDS) === 0) {
            $logger->warning(self::LOG_PREFIX.'Intents::GUILDS is not enabled, so DiscordPHP never fills its guild cache and the server count will be wrong.');
        }
        $logger->info(self::LOG_PREFIX."Attached as cluster {$topology->clusterId} of ".($topology->clusterCount ?? 'unknown')
            .", shard {$topology->shardId} of {$topology->shardCount}.");

        // Started a tick later, so listeners attached right after attach() see every event.
        // An explicit botId lets the heartbeat start before the gateway connects: a booting
        // bot reads "connecting" on its status page rather than dead.
        $loop = $this->discord->getLoop();
        if ($options->botId !== null && $options->botId !== '') {
            $loop->futureTick(fn () => $this->stopped ? null : $this->heartbeat?->start());
        }

        if ($initialised) {
            $loop->futureTick(fn () => $this->start());
        } else {
            $this->listen('init', fn () => $this->start());
        }
    }

    private function start(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->heartbeat?->onReady();
        $this->heartbeat?->start();
        $this->stats?->start();
        $this->commands?->start();
    }

    /** Listener exceptions stay here: they must never surface inside DiscordPHP's dispatch. */
    private function listen(string $event, Closure $listener): void
    {
        $guarded = function (mixed ...$arguments) use ($event, $listener): void {
            try {
                $listener(...$arguments);
            } catch (Throwable $error) {
                $this->discord->getLogger()->error(self::LOG_PREFIX."Handling {$event} failed: {$error->getMessage()}");
            }
        };
        $this->discord->on($event, $guarded);
        $this->wired[] = [$event, $guarded];
    }

    /**
     * An `error` with no listener is logged instead, and `disabled` is always
     * logged: both need a human, and neither may throw into the bot.
     *
     * @param  list<mixed>  $arguments
     */
    private function relay(string $event, array $arguments, string $message): void
    {
        $unheard = $event === 'error' && $this->listeners('error') === [];
        if ($unheard || $event === 'disabled') {
            $this->discord->getLogger()->warning(self::LOG_PREFIX.$message);
        }
        if ($unheard) {
            return;
        }

        try {
            $this->emit($event, $arguments);
        } catch (Throwable $error) {
            $this->discord->getLogger()->error(self::LOG_PREFIX."A {$event} listener threw: {$error->getMessage()}");
        }
    }

    /** A 401 means the token is wrong for every endpoint; one log line covers all three. */
    private function unauthorized(): void
    {
        foreach ([$this->stats, $this->heartbeat, $this->commands] as $publisher) {
            $publisher?->disable(401, 'unauthorized', '', true);
        }
    }

    /**
     * DiscordPHP exposes its resolved options only through __get, and emits
     * `init` exactly once with no public flag for it, so both are read in its scope.
     *
     * @return array{array<mixed>, bool}
     */
    private static function inspect(Discord $discord): array
    {
        $read = Closure::bind(static fn (Discord $client): array => [
            is_array($client->options) ? $client->options : [],
            // @phpstan-ignore nullCoalesce.property (absent if DiscordPHP ever renames it; then attach() waits for `init`)
            (bool) ($client->emittedInit ?? false),
        ], null, Discord::class);

        return $read === null ? [[], false] : $read($discord);
    }

    /** GUILD_CREATE/GUILD_DELETE carry a Guild part, or the raw payload for an unavailable guild. */
    private static function guildId(mixed $guild): ?string
    {
        $id = match (true) {
            $guild instanceof Guild => $guild->id,
            $guild instanceof stdClass => $guild->id ?? null,
            default => null,
        };

        return is_scalar($id) ? (string) $id : null;
    }
}
