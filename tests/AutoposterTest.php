<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Tests;

use Exception;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use RuntimeException;
use TheDiscordList\DiscordPHP\Autoposter;
use TheDiscordList\DiscordPHP\Options;
use TheDiscordList\DiscordPHP\PostResult;
use TheDiscordList\DiscordPHP\PublishException;
use TheDiscordList\DiscordPHP\Tests\Support\FakeDiscord;
use TheDiscordList\DiscordPHP\Tests\Support\FakeLoop;
use TheDiscordList\DiscordPHP\Tests\Support\FakeTransport;

use function React\Promise\resolve;

final class AutoposterTest extends TestCase
{
    private FakeLoop $loop;

    private FakeTransport $transport;

    private TestHandler $log;

    private FakeDiscord $discord;

    /** @var list<array{string, mixed, mixed, mixed}> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->loop = new FakeLoop;
        $this->transport = new FakeTransport;
        $this->log = new TestHandler;
        $this->discord = $this->discord();
    }

    // Request shaping

    public function test_stats_post_five_seconds_after_init_with_full_request_shape(): void
    {
        $this->poster();
        $this->discord->fireInit();

        $this->loop->advance(4.9);
        $this->assertCount(0, $this->transport->to('stats'));

        $this->loop->advance(0.2);
        $stats = $this->transport->to('stats');
        $this->assertCount(1, $stats);
        $this->assertSame('https://thediscordlist.com/api/bots/42/stats', $stats[0]['url']);
        $this->assertSame('{"server_count":3,"shard_count":1}', $stats[0]['raw']);
        $this->assertSame('Bearer tok', $stats[0]['headers']['Authorization']);
        $this->assertSame('application/json', $stats[0]['headers']['Content-Type']);
        $this->assertSame('TheDiscordList-discordphp/0.1.0 (+https://github.com/TheDiscordList/integration-discordphp)', $stats[0]['headers']['User-Agent']);
        $this->assertContains(['posted', 'stats', 200, null], $this->events);
    }

    public function test_nothing_but_an_explicit_bot_id_heartbeat_runs_before_init(): void
    {
        $this->poster();
        $this->loop->advance(600);

        $this->assertSame([], $this->transport->requests);
    }

    public function test_heartbeat_before_ready_omits_optional_fields(): void
    {
        $this->poster(['botId' => '42']);
        $this->loop->advance(0);

        $beat = $this->transport->to('heartbeat')[0];
        $this->assertSame('https://thediscordlist.com/api/bots/42/heartbeat', $beat['url']);
        $this->assertSame('{"cluster":0,"cluster_count":1,"shards":[{"id":0,"status":"connecting"}]}', $beat['raw']);
    }

    public function test_heartbeat_after_ready_reports_latency_and_guilds(): void
    {
        $this->poster();
        $this->discord->emit('heartbeat-ack', [41.6, $this->discord]);
        $this->discord->fireInit();
        $this->loop->advance(0);

        $this->assertSame(
            ['cluster' => 0, 'cluster_count' => 1, 'shards' => [['id' => 0, 'status' => 'ready', 'latency_ms' => 42, 'guilds' => 3]]],
            $this->transport->to('heartbeat')[0]['body'],
        );
    }

    public function test_heartbeat_follows_the_servers_grace_window(): void
    {
        $this->transport->respond('heartbeat', new Response(200, [], '{"cluster":0,"status":"ok","shard_count":1,"next_beat_within_seconds":150}'));
        $this->poster();
        $this->discord->fireInit();

        $this->loop->advance(0);
        $this->loop->advance(49);
        $this->assertCount(1, $this->transport->to('heartbeat'));

        // 50s, plus up to 5s of jitter.
        $this->loop->advance(7);
        $this->assertCount(2, $this->transport->to('heartbeat'));
    }

    public function test_heartbeats_are_chained_with_up_to_five_seconds_of_jitter(): void
    {
        $poster = $this->poster();
        $at = [];
        $poster->on('posted', function (string $type) use (&$at): void {
            if ($type === 'heartbeat') {
                $at[] = $this->loop->now;
            }
        });
        $this->discord->fireInit();
        $this->loop->advance(600);

        $gaps = array_map(fn (float $from, float $to): float => $to - $from, array_slice($at, 0, -1), array_slice($at, 1));
        $this->assertGreaterThan(15, count($gaps));
        foreach ($gaps as $gap) {
            $this->assertGreaterThan(29.99, $gap);
            $this->assertLessThan(35.01, $gap);
        }
        $this->assertGreaterThan(1, count(array_unique(array_map('strval', $gaps))));
    }

    public function test_connection_loss_and_reconnect_beat_10_seconds_after_the_last_beat(): void
    {
        $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(0);

        $this->discord->emit('error', [new Exception('socket closed'), $this->discord]);
        $this->loop->advance(9.9);
        $this->assertCount(1, $this->transport->to('heartbeat'));
        $this->loop->advance(0.2);
        $this->assertSame('connecting', $this->lastBeat()['status']);

        $this->discord->emit('reconnected', [$this->discord]);
        $this->loop->advance(10);
        $this->assertSame('ready', $this->lastBeat()['status']);
        $this->assertCount(3, $this->transport->to('heartbeat'));
    }

    public function test_a_shard_that_stops_acking_reads_connecting(): void
    {
        $this->poster();
        $this->discord->fireInit();

        $this->loop->advance(120);
        $this->assertSame('ready', $this->lastBeat()['status']);

        $this->loop->advance(30);
        $this->assertSame('connecting', $this->lastBeat()['status']);

        $this->discord->emit('heartbeat-ack', [50.0, $this->discord]);
        $this->loop->advance(30);
        $this->assertSame('ready', $this->lastBeat()['status']);
    }

    // Sharding

    public function test_a_sharded_instance_heartbeats_as_its_own_cluster_and_leaves_stats_to_shard_0(): void
    {
        $this->discord = $this->discord(['shard' => [2, 4]]);
        $poster = $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(400);

        $this->assertSame(
            ['cluster' => 2, 'cluster_count' => 4, 'shards' => [['id' => 2, 'status' => 'ready', 'guilds' => 3]]],
            $this->transport->to('heartbeat')[0]['body'],
        );
        $this->assertSame([], $this->transport->to('stats'));
        $this->assertSame([], $this->transport->to('commands'));
        $this->assertSame('not-elected', $this->settle($poster->postStats())->skipped);
    }

    public function test_shard_0_of_several_needs_a_server_count(): void
    {
        $this->discord = $this->discord(['shardId' => 0, 'shardCount' => 2]);
        $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(400);

        $this->assertSame([], $this->transport->to('stats'));
        $this->assertContains(['disabled', 'stats', null, 'server-count-unknown'], $this->events);
        $this->assertTrue($this->log->hasWarningThatContains('serverCount'));
    }

    public function test_shard_0_posts_the_server_count_callback_total(): void
    {
        $this->discord = $this->discord(['shardId' => 0, 'shardCount' => 2]);
        $this->poster(['serverCount' => fn (): PromiseInterface => resolve(1234)]);
        $this->discord->fireInit();
        $this->loop->advance(5);

        $this->assertSame(['server_count' => 1234, 'shard_count' => 2], $this->transport->to('stats')[0]['body']);
    }

    public function test_a_throwing_server_count_callback_is_reported_not_raised(): void
    {
        $this->poster(['serverCount' => function (): int {
            throw new RuntimeException('redis down');
        }]);
        $this->discord->fireInit();
        $this->loop->advance(5);

        $this->assertSame([], $this->transport->to('stats'));
        $this->assertSame('stats: redis down', $this->firstError()->getMessage());
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function unknownClusterCounts(): iterable
    {
        yield 'shard id outside the count' => [['shard' => [5, 2]], []];
        yield 'non-integer shard pair' => [['shard' => ['a', 'b']], []];
        yield 'cluster override from an unset env var' => [[], ['cluster' => ['id' => 0, 'count' => 0]]];
    }

    /**
     * @param  array<string, mixed>  $discordOptions
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('unknownClusterCounts')]
    public function test_an_unknown_cluster_count_never_sends_a_heartbeat(array $discordOptions, array $options): void
    {
        $this->discord = $this->discord($discordOptions);
        $poster = $this->poster($options + ['botId' => '42']);
        $this->discord->fireInit();
        $this->loop->advance(300);

        $this->assertSame([], $this->transport->to('heartbeat'));
        $this->assertContains(['disabled', 'heartbeat', null, 'cluster-count-unknown'], $this->events);
        $this->assertTrue($this->log->hasWarningThatContains('cluster option'));
        $this->assertSame('cluster-count-unknown', $this->settle($poster->postHeartbeat())->skipped);
        $this->assertSame([], $this->transport->to('heartbeat'));
    }

    public function test_cluster_override(): void
    {
        $this->poster(['cluster' => ['id' => 1, 'count' => 3]]);
        $this->discord->fireInit();
        $this->loop->advance(30);

        $this->assertSame(1, $this->transport->to('heartbeat')[0]['body']['cluster']);
        $this->assertSame(3, $this->transport->to('heartbeat')[0]['body']['cluster_count']);
        $this->assertSame([], $this->transport->to('stats'));
    }

    // Status handling

    public function test_401_disables_every_publisher_and_logs_once(): void
    {
        $this->transport->respond('stats', new Response(401));
        $this->discord->commandList = [['name' => 'ping']];
        $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(5);
        $this->loop->advance(900);

        $this->assertCount(1, $this->transport->to('stats'));
        $this->assertCount(1, $this->transport->to('heartbeat'));
        $this->assertSame([], $this->transport->to('commands'));
        $this->assertSame([['disabled', 'stats', 401, 'unauthorized']], $this->eventsNamed('disabled'));
        $this->assertCount(1, array_filter($this->log->getRecords(), fn ($record): bool => str_contains($record['message'], '401')));
    }

    public function test_403_suspends_heartbeats_for_30_minutes_then_reprobes(): void
    {
        $this->transport->respond('heartbeat', new Response(403));
        $this->poster();
        $this->discord->fireInit();

        $this->loop->advance(1799);
        $this->assertCount(1, $this->transport->to('heartbeat'));

        $this->loop->advance(1);
        $this->assertCount(2, $this->transport->to('heartbeat'));
        $this->assertSame(403, $this->firstError()->status);
        $this->assertSame([], $this->eventsNamed('disabled'));
    }

    public function test_404_disables_that_publisher_without_blaming_the_token(): void
    {
        $this->transport->respond('stats', new Response(404));
        $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(600);

        $this->assertCount(1, $this->transport->to('stats'));
        $this->assertGreaterThan(10, count($this->transport->to('heartbeat')));
        $this->assertSame([['disabled', 'stats', 404, 'not-found']], $this->eventsNamed('disabled'));
        $this->assertTrue($this->log->hasWarningThatContains('server listing'));
        $this->assertFalse($this->log->hasWarningThatContains('token'));
    }

    public function test_409_disables_commands(): void
    {
        $this->transport->respond('commands', new Response(409));
        $this->discord->commandList = [['name' => 'ping']];
        $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(10);

        $this->assertSame([['disabled', 'commands', 409, 'conflict']], $this->eventsNamed('disabled'));
    }

    public function test_a_manual_post_rearms_a_disabled_publisher(): void
    {
        $this->transport->respond('stats', new Response(404));
        $poster = $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(65);

        $result = $this->settle($poster->postStats());

        $this->assertTrue($result->ok);
        $this->assertCount(2, $this->transport->to('stats'));

        $this->discord->guildCache['g9'] = true;
        $this->loop->advance(300);
        $this->assertCount(3, $this->transport->to('stats'));
    }

    public function test_429_suspends_stats_and_commands_but_not_heartbeats(): void
    {
        $this->transport->respond('stats', new Response(429, ['Retry-After' => '120']));
        $this->discord->commandList = [['name' => 'ping']];
        $poster = $this->poster();
        $this->discord->fireInit();

        $this->loop->advance(35);
        $this->assertCount(1, $this->transport->to('stats'));
        $this->assertSame([], $this->transport->to('commands'));
        $this->assertCount(2, $this->transport->to('heartbeat'));
        $this->assertSame('suspended', $this->settle($poster->postCommands())->skipped);

        $this->loop->advance(93);
        $this->assertTrue($this->settle($poster->postCommands())->ok);
    }

    public function test_5xx_is_retried_with_backoff(): void
    {
        $this->transport->respond('stats', new Response(503), new Response(502));
        $this->poster();
        $this->discord->fireInit();

        $this->loop->advance(5);
        $this->assertCount(1, $this->transport->to('stats'));
        $this->loop->advance(1);
        $this->assertCount(2, $this->transport->to('stats'));
        $this->loop->advance(2);
        $this->assertCount(3, $this->transport->to('stats'));
        $this->assertContains(['posted', 'stats', 200, null], $this->events);
        $this->assertSame([], $this->eventsNamed('error'));
    }

    public function test_transient_failures_stop_after_three_attempts(): void
    {
        $this->transport->respond('stats', new Response(500), new RuntimeException('Connection refused'), new Response(503));
        $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(299);

        $this->assertCount(3, $this->transport->to('stats'));
        $error = $this->firstError();
        $this->assertSame(503, $error->status);
        $this->assertTrue($error->retryable);
        $this->assertSame('stats', $error->type);
    }

    public function test_payload_errors_are_reported_once_and_do_not_disable(): void
    {
        $this->transport->respond('stats', new Response(422, [], '{"message":"bad"}'), new Response(422));
        $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(5);
        $this->loop->advance(300);

        $this->assertCount(2, $this->transport->to('stats'));
        $this->assertCount(1, $this->eventsNamed('error'));
        $this->assertSame([], $this->eventsNamed('disabled'));
    }

    // Missing token

    /** @return iterable<string, array{?string}> */
    public static function missingTokens(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'blank' => ['   '];
    }

    #[DataProvider('missingTokens')]
    public function test_a_missing_token_returns_an_inert_poster(?string $token): void
    {
        $poster = Autoposter::attach($this->discord, $token);
        $this->discord->fireInit();
        $this->loop->advance(600);

        $this->assertSame([], $this->transport->requests);
        $this->assertSame(0, $this->loop->timerCount());
        $this->assertSame([], $this->discord->listeners('init'));
        $this->assertTrue($this->log->hasWarningThatContains('No stats token'));
        $this->assertSame('disabled', $this->settle($poster->postStats())->skipped);
        $this->assertSame('disabled', $this->settle($poster->postHeartbeat())->skipped);
        $this->assertSame('disabled', $this->settle($poster->postCommands())->skipped);
    }

    // Commands

    public function test_commands_are_posted_raw_once_after_ready(): void
    {
        $this->discord->commandList = [[
            'id' => '1',
            'application_id' => '42',
            'type' => 1,
            'name' => 'echo',
            'description' => 'Echo text',
            'default_member_permissions' => '8',
            'nsfw' => false,
            'options' => [(object) ['type' => 3, 'name' => 'text', 'description' => 'What to say', 'required' => true]],
        ]];
        $this->poster();
        $this->discord->fireInit();

        $this->loop->advance(9.9);
        $this->assertSame([], $this->transport->to('commands'));
        $this->loop->advance(600);

        $commands = $this->transport->to('commands');
        $this->assertCount(1, $commands);
        $this->assertSame(
            '{"commands":[{"id":"1","application_id":"42","type":1,"name":"echo","description":"Echo text","default_member_permissions":"8","nsfw":false,"options":[{"type":3,"name":"text","description":"What to say","required":true}]}]}',
            $commands[0]['raw'],
        );
    }

    public function test_an_empty_command_list_is_not_posted_unless_opted_in(): void
    {
        $poster = $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(10);

        $this->assertSame([], $this->transport->to('commands'));
        $this->assertSame('empty', $this->settle($poster->postCommands())->skipped);

        $this->setUp();
        $this->poster(['postEmptyCommands' => true]);
        $this->discord->fireInit();
        $this->loop->advance(10);

        $this->assertSame('{"commands":[]}', $this->transport->to('commands')[0]['raw']);
    }

    public function test_a_failed_command_fetch_is_reported(): void
    {
        $this->discord->commandsError = new RuntimeException('Discord 503');
        $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(10);

        $this->assertSame([], $this->transport->to('commands'));
        $this->assertSame('commands: Discord 503', $this->firstError()->getMessage());
    }

    // Stats cadence

    public function test_min_gap_holds_a_manual_post_and_sends_it_later(): void
    {
        $poster = $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(5);

        $this->discord->guildCache['g4'] = true;
        $this->assertSame('min-gap', $this->settle($poster->postStats())->skipped);

        $this->loop->advance(59.9);
        $this->assertCount(1, $this->transport->to('stats'));
        $this->loop->advance(0.2);
        $this->assertSame(4, $this->transport->to('stats')[1]['body']['server_count']);
    }

    public function test_guild_joins_are_debounced(): void
    {
        $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(100);

        $this->discord->guildCache['g4'] = true;
        $this->discord->emit('GUILD_CREATE', [(object) ['id' => 'g4'], $this->discord, null]);
        $this->discord->guildCache['g5'] = true;
        $this->discord->emit('GUILD_CREATE', [(object) ['id' => 'g5'], $this->discord, null]);

        $this->loop->advance(19.9);
        $this->assertCount(1, $this->transport->to('stats'));
        $this->loop->advance(0.2);
        $this->assertCount(2, $this->transport->to('stats'));
        $this->assertSame(5, $this->transport->to('stats')[1]['body']['server_count']);
    }

    public function test_unavailable_guilds_still_count(): void
    {
        $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(5);

        unset($this->discord->guildCache['g1']);
        $this->discord->emit('GUILD_DELETE', [(object) ['id' => 'g1', 'unavailable' => true], $this->discord, true]);
        $this->loop->advance(400);

        $this->assertCount(1, $this->transport->to('stats'));
        $this->assertSame(3, $this->lastBeat()['guilds']);

        $this->discord->guildCache['g1'] = true;
        $this->discord->emit('GUILD_CREATE', [(object) ['id' => 'g1'], $this->discord, null]);
        $this->loop->advance(60);
        $this->assertCount(1, $this->transport->to('stats'));
    }

    public function test_an_unchanged_count_is_republished_only_after_30_minutes(): void
    {
        $this->poster();
        $this->discord->fireInit();

        $this->loop->advance(2099);
        $this->assertCount(1, $this->transport->to('stats'));
        $this->loop->advance(2);
        $this->assertCount(2, $this->transport->to('stats'));
    }

    // Lifecycle

    public function test_attach_after_init_starts_immediately(): void
    {
        $this->discord->markInitialised();
        $this->poster();
        $this->loop->advance(5);

        $this->assertCount(1, $this->transport->to('stats'));
        $this->assertSame('ready', $this->lastBeat()['status']);
    }

    public function test_stop_cancels_timers_and_detaches_listeners(): void
    {
        $poster = $this->poster();
        $this->discord->fireInit();
        $this->loop->advance(0);

        $poster->stop();
        $this->loop->advance(600);

        $this->assertCount(1, $this->transport->requests);
        $this->assertSame(0, $this->loop->timerCount());
        $this->assertSame([], $this->discord->listeners('GUILD_CREATE'));
        $this->assertSame('stopped', $this->settle($poster->postStats())->skipped);
    }

    public function test_closing_the_client_stops_the_poster(): void
    {
        $this->poster();
        $this->discord->fireInit();
        $this->discord->emit('closed', [$this->discord]);

        $this->assertSame(0, $this->loop->timerCount());
        $this->assertSame([], $this->discord->listeners('heartbeat-ack'));
    }

    public function test_a_throwing_listener_never_reaches_the_caller(): void
    {
        $poster = $this->poster();
        $poster->on('posted', function (): void {
            throw new RuntimeException('listener bug');
        });

        $this->assertTrue($this->settle($poster->postStats())->ok);
        $this->assertTrue($this->log->hasErrorThatContains('listener bug'));
    }

    public function test_missing_guilds_intent_warns(): void
    {
        $this->discord = $this->discord(['intents' => 0]);
        $this->poster();

        $this->assertTrue($this->log->hasWarningThatContains('Intents::GUILDS'));
    }

    public function test_unknown_options_are_rejected_at_attach(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Options::fromArray(['statsIntervall' => 10]);
    }

    public function test_version_matches_the_changelog(): void
    {
        $changelog = (string) file_get_contents(__DIR__.'/../CHANGELOG.md');

        $this->assertStringContainsString('## ['.Autoposter::VERSION.']', $changelog);
    }

    /** @param array<string, mixed> $options */
    private function discord(array $options = []): FakeDiscord
    {
        return new FakeDiscord($this->loop, new Logger('test', [$this->log]), $options);
    }

    /** @param array<string, mixed> $options */
    private function poster(array $options = []): Autoposter
    {
        $this->events = [];
        $poster = Autoposter::create($this->discord, 'tok', Options::fromArray($options), $this->transport, fn (): float => $this->loop->now);
        foreach (['posted', 'error', 'disabled'] as $event) {
            $poster->on($event, function (mixed ...$arguments) use ($event): void {
                $this->events[] = [$event, ...array_pad($arguments, 3, null)];
            });
        }

        return $poster;
    }

    /** @return list<array{string, mixed, mixed, mixed}> */
    private function eventsNamed(string $name): array
    {
        return array_values(array_filter($this->events, fn (array $event): bool => $event[0] === $name));
    }

    private function firstError(): PublishException
    {
        $error = $this->eventsNamed('error')[0][1] ?? null;
        $this->assertInstanceOf(PublishException::class, $error);

        return $error;
    }

    /** @return array<string, mixed> */
    private function lastBeat(): array
    {
        $beats = $this->transport->to('heartbeat');
        $this->assertNotEmpty($beats);

        return end($beats)['body']['shards'][0];
    }

    /** @param PromiseInterface<PostResult> $promise */
    private function settle(PromiseInterface $promise): PostResult
    {
        $result = null;
        $promise->then(function (PostResult $value) use (&$result): void {
            $result = $value;
        });
        $this->assertInstanceOf(PostResult::class, $result, 'The promise did not resolve to a PostResult.');

        return $result;
    }
}
