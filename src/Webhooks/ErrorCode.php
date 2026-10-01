<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Webhooks;

enum ErrorCode: string
{
    case MissingSignature = 'MISSING_SIGNATURE';
    case MalformedSignature = 'MALFORMED_SIGNATURE';
    case StaleTimestamp = 'STALE_TIMESTAMP';
    case InvalidSignature = 'INVALID_SIGNATURE';
    case MalformedBody = 'MALFORMED_BODY';
}
