<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheDiscordList\DiscordPHP\Topology;

final class TopologyTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, ?array<string, mixed>, array{string, int, ?int, int, int, bool}}>
     */
    public static function layouts(): iterable
    {
        yield 'unsharded' => [[], null, ['single', 0, 1, 0, 1, true]];
        yield 'nulls from the options resolver' => [['shard' => null, 'shardId' => null, 'shardCount' => null], null, ['single', 0, 1, 0, 1, true]];
        yield 'shard pair' => [['shard' => [2, 4]], null, ['sharded', 2, 4, 2, 4, false]];
        yield 'shard_id + num_shards' => [['shard_id' => 0, 'num_shards' => 3], null, ['sharded', 0, 3, 0, 3, true]];
        yield 'shardId + shardCount' => [['shardId' => 1, 'shardCount' => 2], null, ['sharded', 1, 2, 1, 2, false]];
        yield 'shard pair wins, like identify()' => [['shard' => [1, 2], 'shardId' => 0, 'shardCount' => 8], null, ['sharded', 1, 2, 1, 2, false]];
        yield 'half a pair is not sharded, like identify()' => [['shardId' => 3], null, ['single', 0, 1, 0, 1, true]];
        yield 'id outside count' => [['shard' => [5, 2]], null, ['sharded', 5, null, 0, 1, false]];
        yield 'non-integers' => [['shard' => ['a', 'b']], null, ['sharded', 0, null, 0, 1, true]];
        yield 'too many clusters for the API' => [['shard' => [0, 1500]], null, ['sharded', 0, null, 0, 1500, true]];
        yield 'override' => [['shard' => [3, 4]], ['id' => 1, 'count' => 2], ['custom', 1, 2, 3, 4, false]];
        yield 'override with count 0 (unset env var)' => [[], ['id' => 0, 'count' => 0], ['custom', 0, null, 0, 1, true]];
        yield 'override missing count' => [[], ['id' => 0], ['custom', 0, null, 0, 1, true]];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  ?array<string, mixed>  $cluster
     * @param  array{string, int, ?int, int, int, bool}  $expected
     */
    #[DataProvider('layouts')]
    public function test_detect(array $options, ?array $cluster, array $expected): void
    {
        $topology = Topology::detect($options, $cluster);

        $this->assertSame($expected, [
            $topology->kind,
            $topology->clusterId,
            $topology->clusterCount,
            $topology->shardId,
            $topology->shardCount,
            $topology->isStatsPublisher(),
        ]);
    }
}
