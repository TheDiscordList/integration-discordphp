<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\StreamSelectLoop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use TheDiscordList\DiscordPHP\Http\BrowserTransport;

/**
 * The one test on a real loop and a real socket: the default transport must
 * resolve (not reject) error statuses and must not follow redirects.
 */
final class BrowserTransportTest extends TestCase
{
    public function test_posts_on_the_loop_and_resolves_error_statuses(): void
    {
        $loop = new StreamSelectLoop;
        $received = [];
        $server = new HttpServer($loop, function (ServerRequestInterface $request) use (&$received): Response {
            $received[] = [$request->getMethod(), $request->getUri()->getPath(), $request->getHeaderLine('Authorization'), (string) $request->getBody()];

            return $request->getUri()->getPath() === '/redirect'
                ? new Response(302, ['Location' => '/elsewhere'])
                : new Response(429, ['Retry-After' => '7'], '{"message":"Too Many Attempts."}');
        });
        $socket = new SocketServer('127.0.0.1:0', [], $loop);
        $server->listen($socket);
        $base = str_replace('tcp://', 'http://', (string) $socket->getAddress());

        $statuses = [];
        $transport = new BrowserTransport($loop);
        foreach (['/bots/1/stats', '/redirect'] as $path) {
            $transport->post($base.$path, ['Authorization' => 'Bearer tok', 'Content-Type' => 'application/json'], '{"server_count":1}')
                ->then(function (ResponseInterface $response) use (&$statuses, $socket, $loop): void {
                    $statuses[] = $response->getStatusCode();
                    if (count($statuses) === 2) {
                        $socket->close();
                        $loop->stop();
                    }
                });
        }
        $deadline = $loop->addTimer(5, fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($deadline);

        sort($statuses);
        $this->assertSame([302, 429], $statuses);
        $this->assertCount(2, $received);
        $this->assertContains(['POST', '/bots/1/stats', 'Bearer tok', '{"server_count":1}'], $received);
    }
}
