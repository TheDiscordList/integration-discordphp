<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Http;

/**
 * @internal
 */
final class Outcome
{
    public const OK = 'ok';

    public const PAYLOAD = 'payload';

    public const AUTH = 'auth';

    public const PLAN = 'plan';

    public const CONFIG = 'config';

    public const THROTTLED = 'throttled';

    public const TRANSIENT = 'transient';

    /** Not sent: the client-wide 429 suspension is active. */
    public const DROPPED = 'dropped';

    /**
     * @param  self::*  $kind
     * @param  array<mixed>  $body
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?int $status = null,
        public readonly array $body = [],
        public readonly string $message = '',
    ) {}
}
