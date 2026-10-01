<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Webhooks;

use RuntimeException;

final class VerificationException extends RuntimeException
{
    public function __construct(public readonly ErrorCode $reason, string $message)
    {
        parent::__construct($message);
    }
}
