<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Http;

use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;

/**
 * Sends one POST without blocking the loop. Must resolve for every HTTP status
 * and reject only on network errors and timeouts.
 */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     * @return PromiseInterface<ResponseInterface>
     */
    public function post(string $url, array $headers, string $body): PromiseInterface;
}
