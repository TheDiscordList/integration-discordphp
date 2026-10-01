<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP;

/**
 * Where this `Discord` instance sits in the bot's layout.
 *
 * DiscordPHP opens one gateway connection per instance, so a sharded bot runs
 * one instance per shard and each instance is its own cluster: cluster id =
 * shard id, cluster count = shard count.
 */
final class Topology
{
    /** The API's `cluster_count` ceiling. */
    public const MAX_CLUSTERS = 1024;

    /**
     * @param  'single'|'sharded'|'custom'  $kind
     * @param  ?int  $clusterCount  Null when it cannot be trusted; heartbeats must not start.
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $clusterId,
        public readonly ?int $clusterCount,
        public readonly int $shardId,
        public readonly int $shardCount,
    ) {}

    public function isStatsPublisher(): bool
    {
        return $this->clusterId === 0;
    }

    /**
     * @param  array<mixed>  $discordOptions  The resolved `$discord->options`.
     * @param  ?array<mixed>  $cluster  The `cluster` override, `['id' => int, 'count' => int]`.
     */
    public static function detect(array $discordOptions, ?array $cluster = null): self
    {
        // Same precedence as Discord::identify().
        $pair = match (true) {
            isset($discordOptions['shard']) && is_array($discordOptions['shard']) => array_values($discordOptions['shard']) + [null, null],
            isset($discordOptions['shard_id'], $discordOptions['num_shards']) => [$discordOptions['shard_id'], $discordOptions['num_shards']],
            isset($discordOptions['shardId'], $discordOptions['shardCount']) => [$discordOptions['shardId'], $discordOptions['shardCount']],
            default => null,
        };

        $sharded = $pair !== null;
        $validShard = $sharded && self::fits($pair[0], $pair[1], PHP_INT_MAX);
        $shardId = $validShard ? (int) $pair[0] : 0;
        $shardCount = $validShard ? (int) $pair[1] : 1;

        if ($cluster !== null) {
            $id = $cluster['id'] ?? null;
            $count = $cluster['count'] ?? null;

            return new self(
                'custom',
                is_int($id) ? $id : 0,
                self::fits($id, $count, self::MAX_CLUSTERS) ? (int) $count : null,
                $shardId,
                $shardCount,
            );
        }

        if (! $sharded) {
            return new self('single', 0, 1, 0, 1);
        }

        return new self(
            'sharded',
            is_int($pair[0]) ? $pair[0] : 0,
            $validShard && $shardCount <= self::MAX_CLUSTERS ? $shardCount : null,
            $shardId,
            $shardCount,
        );
    }

    private static function fits(mixed $id, mixed $count, int $max): bool
    {
        return is_int($id) && is_int($count) && $count >= 1 && $count <= $max && $id >= 0 && $id < $count;
    }
}
