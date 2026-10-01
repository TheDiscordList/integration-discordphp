<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Http;

use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;

/**
 * The default transport: react/http's Browser on DiscordPHP's own loop.
 */
final class BrowserTransport implements Transport
{
    public const TIMEOUT = 10.0;

    private readonly Browser $browser;

    public function __construct(LoopInterface $loop, float $timeout = self::TIMEOUT)
    {
        $this->browser = (new Browser(null, $loop))
            ->withTimeout($timeout)
            ->withRejectErrorResponse(false)
            ->withFollowRedirects(false);
    }

    public function post(string $url, array $headers, string $body): PromiseInterface
    {
        return $this->browser->post($url, $headers, $body);
    }
}
