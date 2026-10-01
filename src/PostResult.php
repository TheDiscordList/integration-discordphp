<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP;

final class PostResult
{
    /**
     * @param  ?string  $skipped  Set when nothing was sent: `unchanged`, `min-gap`, `plan`, `suspended`, `disabled`, …
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $status = null,
        public readonly ?string $skipped = null,
    ) {}

    public static function skipped(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
