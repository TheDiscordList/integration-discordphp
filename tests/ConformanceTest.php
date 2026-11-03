<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Tests;

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use TheDiscordList\DiscordPHP\Autoposter;
use TheDiscordList\DiscordPHP\Http\ApiClient;
use TheDiscordList\DiscordPHP\Http\BrowserTransport;
use TheDiscordList\DiscordPHP\Options;
use TheDiscordList\DiscordPHP\Publishers\CommandsPublisher;
use TheDiscordList\DiscordPHP\Publishers\HeartbeatPublisher;
use TheDiscordList\DiscordPHP\Publishers\Publisher;
use TheDiscordList\DiscordPHP\Publishers\StatsPublisher;
use TheDiscordList\DiscordPHP\Tests\Support\FakeDiscord;
use TheDiscordList\DiscordPHP\Tests\Support\FakeLoop;
use TheDiscordList\DiscordPHP\Tests\Support\FakeTransport;
use TheDiscordList\DiscordPHP\Webhooks\VerificationException;
use TheDiscordList\DiscordPHP\Webhooks\Verifier;

/**
 * tests/fixtures/sdk-conformance.json is shared byte-for-byte by every TheDiscordList SDK.
 */
final class ConformanceTest extends TestCase
{
    public function test_the_sdk_matches_the_shared_fixture(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/fixtures/sdk-conformance.json'), true, 512, JSON_THROW_ON_ERROR);

        $signature = $fixture['webhook_signature'];
        $this->assertNotEmpty($signature['vectors']);
        foreach ($signature['vectors'] as $vector) {
            try {
                Verifier::verify($vector['body'], $vector['header'], $signature['secret'], now: $vector['now']);
                $valid = true;
            } catch (VerificationException) {
                $valid = false;
            }
            $this->assertSame($vector['valid'], $valid, $vector['name']);
        }

        $defaults = $fixture['defaults'];
        $options = new Options;
        foreach ([
            'webhook_signature.tolerance_seconds' => [$signature['tolerance_seconds'], Verifier::DEFAULT_TOLERANCE],
            'base_url' => [$defaults['base_url'], Autoposter::DEFAULT_BASE_URL],
            'options base_url' => [$defaults['base_url'], $options->baseUrl],
            'timeout_seconds' => [$defaults['timeout_seconds'], BrowserTransport::TIMEOUT],
            'retry.attempts' => [$defaults['retry']['attempts'], Publisher::MAX_ATTEMPTS],
            'retry.backoff_base_seconds' => [$defaults['retry']['backoff_base_seconds'], Publisher::BACKOFF_BASE],
            'retry.backoff_max_seconds' => [$defaults['retry']['backoff_max_seconds'], Publisher::BACKOFF_MAX],
            'rate_limit.default_seconds' => [$defaults['rate_limit']['default_seconds'], ApiClient::DEFAULT_RETRY_AFTER],
            'rate_limit.max_seconds' => [$defaults['rate_limit']['max_seconds'], ApiClient::MAX_RETRY_AFTER],
            'rate_limit.jitter_max_seconds' => [$defaults['rate_limit']['jitter_max_seconds'], ApiClient::RETRY_JITTER],
            'forbidden_reprobe_seconds' => [$defaults['forbidden_reprobe_seconds'], Publisher::PLAN_REPROBE],
            'stats.first_delay_seconds' => [$defaults['stats']['first_delay_seconds'], StatsPublisher::FIRST_POST_DELAY],
            'stats.interval_seconds' => [$defaults['stats']['interval_seconds'], $options->statsInterval],
            'stats.debounce_seconds' => [$defaults['stats']['debounce_seconds'], $options->statsDebounce],
            'stats.min_gap_seconds' => [$defaults['stats']['min_gap_seconds'], $options->statsMinGap],
            'stats.force_after_seconds' => [$defaults['stats']['force_after_seconds'], StatsPublisher::FORCE_AFTER],
            'heartbeat.interval_seconds' => [$defaults['heartbeat']['interval_seconds'], $options->heartbeatInterval],
            'heartbeat.jitter_max_seconds' => [$defaults['heartbeat']['jitter_max_seconds'], HeartbeatPublisher::JITTER],
            'commands.first_delay_seconds' => [$defaults['commands']['first_delay_seconds'], CommandsPublisher::DELAY],
            'commands.retry_seconds' => [$defaults['commands']['retry_seconds'], CommandsPublisher::RETRY],
        ] as $name => [$expected, $actual]) {
            $this->assertEquals($expected, $actual, $name);
        }

        // The states a shard actually reports: DiscordPHP exposes no other.
        $loop = new FakeLoop;
        $transport = new FakeTransport;
        $discord = new FakeDiscord($loop, new Logger('test', [new NullHandler]));
        Autoposter::create($discord, 'tok', new Options(botId: '42'), $transport, fn (): float => $loop->now);
        $loop->advance(0);
        $discord->fireInit();
        $loop->advance(10);
        $states = array_map(fn (array $beat): string => $beat['body']['shards'][0]['status'], $transport->to('heartbeat'));
        $this->assertSame(['connecting', 'ready'], $states);
        $this->assertSame([], array_diff($states, $fixture['shard_states']));
    }
}
