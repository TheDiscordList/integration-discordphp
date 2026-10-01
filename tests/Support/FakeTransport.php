<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use TheDiscordList\DiscordPHP\Http\Transport;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Records every request and answers from per-endpoint queues; 200 when a queue is empty.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, endpoint: string, headers: array<string, string>, raw: string, body: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, list<ResponseInterface|Throwable>> */
    private array $queues = [];

    public function respond(string $endpoint, ResponseInterface|Throwable ...$responses): void
    {
        $this->queues[$endpoint] = [...($this->queues[$endpoint] ?? []), ...$responses];
    }

    public function post(string $url, array $headers, string $body): PromiseInterface
    {
        $endpoint = basename((string) parse_url($url, PHP_URL_PATH));
        $this->requests[] = [
            'url' => $url,
            'endpoint' => $endpoint,
            'headers' => $headers,
            'raw' => $body,
            'body' => json_decode($body, true),
        ];

        $next = isset($this->queues[$endpoint]) ? array_shift($this->queues[$endpoint]) : null;
        $next ??= new Response(200, ['Content-Type' => 'application/json'], '{}');

        return $next instanceof Throwable ? reject($next) : resolve($next);
    }

    /** @return list<array{url: string, endpoint: string, headers: array<string, string>, raw: string, body: array<string, mixed>}> */
    public function to(string $endpoint): array
    {
        return array_values(array_filter($this->requests, fn (array $request): bool => $request['endpoint'] === $endpoint));
    }
}
