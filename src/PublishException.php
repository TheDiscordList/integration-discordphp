<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP;

use RuntimeException;
use Throwable;

/**
 * Passed to `error` listeners. Never thrown at the host.
 */
final class PublishException extends RuntimeException
{
    /**
     * @param  string  $type  `stats`, `heartbeat` or `commands`.
     */
    public function __construct(
        string $message,
        public readonly string $type,
        public readonly ?int $status,
        public readonly bool $retryable,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
