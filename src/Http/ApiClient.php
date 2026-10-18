<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Http;

use Closure;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Promise\resolve;

/**
 * The one HTTP client every publisher shares.
 *
 * It keeps a single client-side 429 suspension because the server keeps a
 * single rate-limit counter per client IP. Heartbeats bypass it: a dropped
 * stats post is a stale number, a dropped heartbeat is a false "down" on the
 * public status page.
 *
 * @internal
 */
final class ApiClient
{
    private const DEFAULT_RETRY_AFTER = 60.0;

    private const MAX_RETRY_AFTER = 3600.0;

    private readonly string $token;

    private float $suspendedUntil = 0.0;

    /**
     * @param  Closure(): float  $clock  Monotonic seconds.
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $baseUrl,
        string $token,
        private readonly string $userAgent,
        private readonly Closure $clock,
    ) {
        $this->token = (string) preg_replace('/^Bearer\s+/i', '', trim($token));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return PromiseInterface<Outcome>
     */
    public function post(string $path, array $body, bool $heartbeat = false): PromiseInterface
    {
        if (! $heartbeat && ($this->clock)() < $this->suspendedUntil) {
            return resolve(new Outcome(Outcome::DROPPED));
        }

        try {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            return resolve(new Outcome(Outcome::PAYLOAD, null, [], $error->getMessage()));
        }

        return $this->transport->post(rtrim($this->baseUrl, '/').$path, [
            'Authorization' => 'Bearer '.$this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ], $json)->then(
            fn (ResponseInterface $response): Outcome => $this->classify($response),
            fn (Throwable $error): Outcome => new Outcome(Outcome::TRANSIENT, null, [], $error->getMessage()),
        );
    }

    /**
     * Server precedence is 404 → 429 → 401 → 422 → 403/409 → 200, so plan state
     * is only inferable from a payload that already validated.
     */
    private function classify(ResponseInterface $response): Outcome
    {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $body = json_decode((string) $response->getBody(), true);

            return new Outcome(Outcome::OK, $status, is_array($body) ? $body : []);
        }

        return match (true) {
            $status === 401 => new Outcome(Outcome::AUTH, 401),
            $status === 403 => new Outcome(Outcome::PLAN, 403),
            // "Unknown id, or the id is a server listing". Deliberately never "bad token".
            $status === 404, $status === 409 => new Outcome(Outcome::CONFIG, $status),
            $status === 429 => $this->throttle($response),
            // Any other 4xx would fail the same way again; a timeout or "too early" may not.
            $status >= 400 && $status < 500 && $status !== 408 && $status !== 425 => new Outcome(Outcome::PAYLOAD, $status, [], (string) $response->getBody()),
            default => new Outcome(Outcome::TRANSIENT, $status, [], "TheDiscordList responded {$status}."),
        };
    }

    /** Seconds left on the 429 suspension, 0 once it is over. */
    public function suspendedFor(): float
    {
        return max($this->suspendedUntil - ($this->clock)(), 0.0);
    }

    private function throttle(ResponseInterface $response): Outcome
    {
        $retryAfter = $response->getHeaderLine('Retry-After');
        $seconds = is_numeric($retryAfter) && (float) $retryAfter > 0 ? (float) $retryAfter : self::DEFAULT_RETRY_AFTER;
        $reset = $response->getHeaderLine('X-RateLimit-Reset');
        if (is_numeric($reset)) {
            // A Unix timestamp, so the one comparison that needs the wall clock.
            $seconds = max($seconds, (float) $reset - microtime(true));
        }

        $this->suspendedUntil = ($this->clock)() + min($seconds, self::MAX_RETRY_AFTER) + mt_rand(0, 2000) / 1000;

        return new Outcome(Outcome::THROTTLED, 429);
    }
}
