<?php

namespace BoringO11y\HorizonWorkerStats;

use BoringO11y\HorizonWorkerStats\Contracts\WorkerResourcesRepository;
use BoringO11y\HorizonWorkerStats\Listeners\RecordWorkerResources;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;

class RedisWorkerResourcesRepository implements WorkerResourcesRepository
{
    /**
     * The Redis connection instance.
     *
     * @var RedisFactory
     */
    public $redis;

    /**
     * The bucket interval in seconds.
     *
     * @var int
     */
    private $intervalSeconds;

    /**
     * The number of buckets retained across the window.
     *
     * @var int
     */
    private $bucketCount;

    /**
     * Create a new repository instance.
     *
     * @return void
     */
    public function __construct(RedisFactory $redis)
    {
        $this->redis = $redis;

        $this->intervalSeconds = max(1, (int) config('horizon-worker-stats.interval', 15)) * 60;
        $this->bucketCount = max(1, (int) ceil(
            max(1, (int) config('horizon-worker-stats.retention', 24)) * 3600 / $this->intervalSeconds
        ));
    }

    /**
     * Record what one supervisor's workers used between two samples.
     *
     * A span that crosses a bucket boundary is split between the buckets in
     * proportion to the time it spent in each, so a sample taken just after
     * the boundary does not pull the whole span into the new bucket.
     *
     * @param  string  $supervisor
     * @param  int  $from
     * @param  int  $until
     * @param  float  $memory
     * @param  float  $cpu
     * @return void
     */
    public function record($supervisor, $from, $until, $memory, $cpu)
    {
        if (($span = $until - $from) <= 0) {
            return;
        }

        for ($bucket = $this->bucketFor($from); $bucket < $until; $bucket += $this->intervalSeconds) {
            $start = max($from, $bucket);
            $end = min($until, $bucket + $this->intervalSeconds);

            if (($overlap = $end - $start) <= 0) {
                continue;
            }

            $this->connection()->eval(
                LuaScripts::recordWorkerResources(), 1,
                $this->key($bucket),
                $supervisor,
                $this->number($memory * $overlap),
                $this->number($cpu * $overlap / $span),
                $start,
                $end,
                $this->retentionSeconds()
            );
        }
    }

    /**
     * Get the average memory and CPU cores in use across the retention window.
     *
     * @return array{labels: array<int, int>, memory: array<int, int|null>, cpu: array<int, float|null>, supervisors: array<int, array{name: string, memory: array<int, int|null>, cpu: array<int, float|null>}>}
     */
    public function trends()
    {
        $buckets = $this->buckets(CarbonImmutable::now()->getTimestamp());

        $raw = $this->pipeline(function ($pipe) use ($buckets) {
            foreach ($buckets as $bucket) {
                $pipe->hgetall($this->key($bucket));
            }
        });

        $memory = [];
        $cpu = [];
        $groups = [];

        foreach ($buckets as $i => $bucket) {
            $supervisors = $this->supervisors((array) ($raw[$i] ?? []));

            if (empty($supervisors)) {
                $memory[] = null;
                $cpu[] = null;

                continue;
            }

            $earliest = min(array_column($supervisors, 'from'));
            $latest = max(array_column($supervisors, 'until'));
            $window = max(1, $latest - $earliest);

            $bytes = 0.0;
            $cores = 0.0;

            foreach ($supervisors as $name => $supervisor) {
                // Each supervisor is averaged over the seconds it sampled, then
                // weighted by how much of the bucket it was running for. A gap
                // between its samples is a stretch nobody measured, not a
                // stretch its workers used nothing.
                $share = $this->presence($supervisor, $latest) / $window / $supervisor['covered'];

                $bytes += $supervisor['memory'] * $share;
                $cores += $supervisor['cpu'] * $share;

                $group = $this->group($name);
                $groups[$group][$i]['memory'] = ($groups[$group][$i]['memory'] ?? 0.0) + $supervisor['memory'] * $share;
                $groups[$group][$i]['cpu'] = ($groups[$group][$i]['cpu'] ?? 0.0) + $supervisor['cpu'] * $share;
            }

            $memory[] = (int) round($bytes);
            $cpu[] = round($cores, 3);
        }

        ksort($groups);

        return [
            'labels' => $buckets,
            'memory' => $memory,
            'cpu' => $cpu,
            'supervisors' => array_map(fn ($name) => [
                'name' => (string) $name,
                'memory' => $this->groupSeries($groups[$name], $memory, 'memory', fn ($value) => (int) round($value)),
                'cpu' => $this->groupSeries($groups[$name], $cpu, 'cpu', fn ($value) => round($value, 3)),
            ], array_keys($groups)),
        ];
    }

    /**
     * Get the supervisor a recorded name belongs to, across machines and restarts.
     *
     * Horizon names a supervisor after its master, "{host}-{random}:{name}",
     * and the master's part changes every time Horizon starts. Only the name
     * from the configuration stays put, so that is what the history is kept by.
     * It is taken after the last colon, as a custom master name may hold one.
     *
     * @param  string  $supervisor
     * @return string
     */
    protected function group($supervisor)
    {
        return ($separator = strrpos($supervisor, ':')) !== false
            ? substr($supervisor, $separator + 1)
            : $supervisor;
    }

    /**
     * Line one supervisor's values up with the buckets of the total.
     *
     * A bucket nothing sampled stays null. One that other supervisors sampled
     * but this one did not is zero: it had no workers running there, and the
     * breakdown still adds up to the total.
     *
     * @param  array<int, array{memory: float, cpu: float}>  $values
     * @param  array<int, int|float|null>  $total
     * @param  string  $metric
     * @return array<int, int|float|null>
     */
    protected function groupSeries(array $values, array $total, $metric, callable $round)
    {
        $series = [];

        foreach ($total as $i => $value) {
            $series[] = $value === null ? null : $round($values[$i][$metric] ?? 0.0);
        }

        return $series;
    }

    /**
     * Group a bucket's fields by the supervisor that wrote them.
     *
     * @param  array<string, string>  $hash
     * @return array<string, array{memory: float, cpu: float, covered: int, from: int, until: int}>
     */
    protected function supervisors(array $hash)
    {
        $fields = [];

        foreach ($hash as $field => $value) {
            if (($separator = strrpos($field, '|')) !== false) {
                $fields[substr($field, 0, $separator)][substr($field, $separator + 1)] = $value;
            }
        }

        $supervisors = [];

        foreach ($fields as $name => $values) {
            if (! isset($values['from'], $values['until'])) {
                continue;
            }

            $supervisors[$name] = [
                'memory' => (float) ($values['memory'] ?? 0),
                'cpu' => (float) ($values['cpu'] ?? 0),
                'covered' => max(1, (int) ($values['covered'] ?? 0)),
                'from' => (int) $values['from'],
                'until' => (int) $values['until'],
            ];
        }

        return $supervisors;
    }

    /**
     * Get the seconds of the bucket a supervisor was running for.
     *
     * Supervisors sample out of step, so while a bucket is in progress most of
     * them have yet to write the span ending at the newest sample. One whose
     * latest span ends within the longest span the listener records may still
     * have that span in flight, and is taken to be running up to the newest
     * sample rather than to have stopped at its own.
     *
     * @param  array{from: int, until: int}  $supervisor
     * @param  int  $latest
     * @return int
     */
    protected function presence(array $supervisor, $latest)
    {
        $until = $latest - $supervisor['until'] <= RecordWorkerResources::MAX_GAP
            ? $latest
            : $supervisor['until'];

        return max(1, $until - $supervisor['from']);
    }

    /**
     * Delete all stored resource information.
     *
     * Only the buckets of the current window are known by name. Any left from
     * before the interval was changed expire on their own.
     *
     * @return void
     */
    public function clear()
    {
        $buckets = $this->buckets(CarbonImmutable::now()->getTimestamp());

        $this->pipeline(function ($pipe) use ($buckets) {
            foreach ($buckets as $bucket) {
                $pipe->del($this->key($bucket));
            }
        });
    }

    /**
     * Get the key a bucket is stored under.
     *
     * @param  int  $bucket
     * @return string
     */
    protected function key($bucket)
    {
        return 'worker_stats:'.$bucket;
    }

    /**
     * Format a number for a Lua script without depending on the locale.
     *
     * @param  float  $value
     * @return string
     */
    protected function number($value)
    {
        return sprintf('%.6F', $value);
    }

    /**
     * Get every bucket in the retention window, oldest first.
     *
     * @param  int  $now
     * @return array<int, int>
     */
    protected function buckets($now)
    {
        $current = $this->bucketFor($now);
        $start = $current - ($this->bucketCount - 1) * $this->intervalSeconds;

        $buckets = [];

        for ($bucket = $start; $bucket <= $current; $bucket += $this->intervalSeconds) {
            $buckets[] = $bucket;
        }

        return $buckets;
    }

    /**
     * Get the bucket the given timestamp belongs to.
     *
     * @param  int  $now
     * @return int
     */
    protected function bucketFor($now)
    {
        return intdiv($now, $this->intervalSeconds) * $this->intervalSeconds;
    }

    /**
     * Get how long a bucket is kept for.
     *
     * One interval past the window, so the oldest bucket on the chart has not
     * expired out from under it.
     *
     * @return int
     */
    protected function retentionSeconds()
    {
        return $this->bucketCount * $this->intervalSeconds + $this->intervalSeconds;
    }

    /**
     * Execute commands in a pipeline, falling back to a transaction.
     *
     * phpredis cannot pipeline across a cluster. Every key here carries
     * Horizon's hash-tagged prefix, so a transaction stays on one node.
     *
     * @return array
     */
    protected function pipeline(callable $callback)
    {
        $connection = $this->connection();

        if ($connection instanceof PhpRedisClusterConnection) {
            return $connection->transaction($callback);
        }

        return $connection->pipeline($callback);
    }

    /**
     * Get the Redis connection instance.
     *
     * Horizon's own connection, so the history lives under Horizon's prefix
     * beside the rest of the dashboard's data.
     *
     * @return PhpRedisConnection|PredisConnection
     */
    public function connection()
    {
        /** @var PhpRedisConnection|PredisConnection */
        return $this->redis->connection('horizon');
    }
}
