<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use RuntimeException;
use TheDiscordList\DiscordPHP\Autoposter;
use TheDiscordList\DiscordPHP\Http\ApiClient;
use TheDiscordList\DiscordPHP\Http\Outcome;
use TheDiscordList\DiscordPHP\Tests\Support\FakeTransport;

final class ApiClientTest extends TestCase
{
    private FakeTransport $transport;

    private float $now = 1_700_000_000.0;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport;
    }

    public function test_request_shape(): void
    {
        $this->client('Bearer tok', 'https://example.test/api/')->post('/bots/42/stats', ['server_count' => 1, 'shard_count' => 1]);

        $request = $this->transport->requests[0];
        $this->assertSame('https://example.test/api/bots/42/stats', $request['url']);
        $this->assertSame('{"server_count":1,"shard_count":1}', $request['raw']);
        $this->assertSame([
            'Authorization' => 'Bearer tok',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'TheDiscordList-discordphp/'.Autoposter::VERSION.' (+https://github.com/TheDiscordList/integration-discordphp)',
        ], $request['headers']);
    }

    public function test_slashes_and_unicode_are_not_escaped(): void
    {
        $this->client()->post('/bots/42/commands', ['commands' => [['name' => 'ping', 'description' => 'Pong ✓ a/b']]]);

        $this->assertSame('{"commands":[{"name":"ping","description":"Pong ✓ a/b"}]}', $this->transport->requests[0]['raw']);
    }

    /** @return iterable<string, array{int, string}> */
    public static function statuses(): iterable
    {
        yield '200' => [200, Outcome::OK];
        yield '204' => [204, Outcome::OK];
        yield '400' => [400, Outcome::PAYLOAD];
        yield '401' => [401, Outcome::AUTH];
        yield '403' => [403, Outcome::PLAN];
        yield '404' => [404, Outcome::CONFIG];
        yield '409' => [409, Outcome::CONFIG];
        yield '422' => [422, Outcome::PAYLOAD];
        yield '429' => [429, Outcome::THROTTLED];
        yield '500' => [500, Outcome::TRANSIENT];
        yield '503' => [503, Outcome::TRANSIENT];
        yield '302' => [302, Outcome::TRANSIENT];
    }

    #[DataProvider('statuses')]
    public function test_status_classification(int $status, string $kind): void
    {
        $this->transport->respond('stats', new Response($status));

        $outcome = $this->settle($this->client()->post('/bots/42/stats', []));

        $this->assertSame($kind, $outcome->kind);
        $this->assertSame($status, $outcome->status);
    }

    public function test_network_errors_are_transient(): void
    {
        $this->transport->respond('stats', new RuntimeException('Connection refused'));

        $outcome = $this->settle($this->client()->post('/bots/42/stats', []));

        $this->assertSame(Outcome::TRANSIENT, $outcome->kind);
        $this->assertNull($outcome->status);
    }

    public function test_ok_body_is_decoded(): void
    {
        $this->transport->respond('heartbeat', new Response(200, [], '{"next_beat_within_seconds":90}'));

        $this->assertSame(['next_beat_within_seconds' => 90], $this->settle($this->client()->post('/bots/42/heartbeat', [], true))->body);
    }

    public function test_429_suspends_everything_but_heartbeats_for_retry_after_plus_jitter(): void
    {
        $client = $this->client();
        $this->transport->respond('stats', new Response(429, ['Retry-After' => '30']));
        $client->post('/bots/42/stats', []);

        $this->now += 29;
        $this->assertSame(Outcome::DROPPED, $this->settle($client->post('/bots/42/commands', []))->kind);
        $this->assertSame(Outcome::OK, $this->settle($client->post('/bots/42/heartbeat', [], true))->kind);
        $this->assertCount(2, $this->transport->requests);

        $this->now += 3.1;
        $this->assertSame(Outcome::OK, $this->settle($client->post('/bots/42/stats', []))->kind);
    }

    public function test_429_without_retry_after_waits_60_seconds(): void
    {
        $client = $this->client();
        $this->transport->respond('stats', new Response(429));
        $client->post('/bots/42/stats', []);

        $this->now += 59;
        $this->assertSame(Outcome::DROPPED, $this->settle($client->post('/bots/42/stats', []))->kind);
        $this->now += 3.1;
        $this->assertSame(Outcome::OK, $this->settle($client->post('/bots/42/stats', []))->kind);
    }

    public function test_429_honours_a_later_rate_limit_reset(): void
    {
        $client = $this->client();
        $this->transport->respond('stats', new Response(429, ['Retry-After' => '1', 'X-RateLimit-Reset' => (string) ($this->now + 300)]));
        $client->post('/bots/42/stats', []);

        $this->now += 299;
        $this->assertSame(Outcome::DROPPED, $this->settle($client->post('/bots/42/stats', []))->kind);
        $this->now += 3.1;
        $this->assertSame(Outcome::OK, $this->settle($client->post('/bots/42/stats', []))->kind);
    }

    private function client(string $token = 'tok', string $baseUrl = Autoposter::DEFAULT_BASE_URL): ApiClient
    {
        return new ApiClient($this->transport, $baseUrl, $token, Autoposter::USER_AGENT, fn (): float => $this->now);
    }

    /** @param PromiseInterface<Outcome> $promise */
    private function settle(PromiseInterface $promise): Outcome
    {
        $result = null;
        $promise->then(function (Outcome $outcome) use (&$result): void {
            $result = $outcome;
        });
        $this->assertInstanceOf(Outcome::class, $result);

        return $result;
    }
}
