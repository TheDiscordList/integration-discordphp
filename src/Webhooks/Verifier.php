<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Webhooks;

use JsonException;

/**
 * Verifies TheDiscordList webhook deliveries. Standard library only, so it
 * works in any PHP app (Laravel, Symfony, Slim, plain PHP) without DiscordPHP.
 */
final class Verifier
{
    public const SIGNATURE_HEADER = 'X-TDL-Signature';

    public const DELIVERY_HEADER = 'X-TDL-Delivery';

    public const ATTEMPT_HEADER = 'X-TDL-Attempt';

    public const EVENT_HEADER = 'X-TDL-Event';

    public const DEFAULT_TOLERANCE = 300;

    /**
     * Checks `X-TDL-Signature` and returns the decoded envelope:
     * `id`, `event`, `created_at`, `listing` {id, type, name, url} and `data`.
     *
     * `$rawBody` must be the exact bytes received. The sender signs the JSON
     * string it posts, so re-encoding a decoded body (`json_encode($request->all())`)
     * escapes slashes and unicode differently and can never verify.
     *
     * @param  int  $tolerance  Two-sided freshness window around `t`, in seconds.
     * @param  ?int  $now  Unix seconds; defaults to `time()`.
     * @return array<string, mixed>
     *
     * @throws VerificationException
     */
    public static function verify(
        string $rawBody,
        ?string $header,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): array {
        if ($header === null || trim($header) === '') {
            throw new VerificationException(ErrorCode::MissingSignature, 'Missing X-TDL-Signature header.');
        }

        $timestamp = null;
        $signature = null;
        foreach (explode(',', $header) as $part) {
            // Split on the first '=' only: a value may itself contain '='.
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = [trim($pair[0]), trim($pair[1])];
            if ($key === 't' && $timestamp === null) {
                $timestamp = $value;
            } elseif ($key === 'v1' && $signature === null && $value !== '') {
                $signature = $value;
            }
        }

        if ($timestamp === null || ! is_numeric($timestamp) || ! is_finite((float) $timestamp) || $signature === null) {
            throw new VerificationException(ErrorCode::MalformedSignature, 'Malformed X-TDL-Signature header.');
        }

        // Against `t`, never `created_at`: that is enqueue time and a retry can be hours older.
        if (abs(($now ?? time()) - (float) $timestamp) > $tolerance) {
            throw new VerificationException(ErrorCode::StaleTimestamp, 'Webhook timestamp is outside the tolerance window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        if (! hash_equals($expected, strtolower($signature))) {
            throw new VerificationException(ErrorCode::InvalidSignature, 'Webhook signature did not match.');
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $payload = null;
        }
        if (! is_array($payload)) {
            throw new VerificationException(ErrorCode::MalformedBody, 'Webhook body is not a JSON object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
