<?php

namespace BoringO11y\HorizonWorkerStats;

class LuaScripts
{
    /**
     * Get the Lua script for recording one supervisor's share of a bucket.
     *
     * Memory and CPU are added to running totals, alongside the seconds they
     * cover and the earliest and latest instant sampled. All of it is written
     * in one call so a reader never sees totals without the span they cover.
     *
     * KEYS[1] - The bucket hash
     * ARGV[1] - The supervisor name
     * ARGV[2] - The memory held across the span, in byte-seconds
     * ARGV[3] - The CPU seconds used across the span
     * ARGV[4] - The start of the span
     * ARGV[5] - The end of the span
     * ARGV[6] - The seconds the bucket is kept for
     *
     * @return string
     */
    public static function recordWorkerResources()
    {
        return <<<'LUA'
            local prefix = ARGV[1] .. '|'
            local start = tonumber(ARGV[4])
            local finish = tonumber(ARGV[5])

            redis.call('hincrbyfloat', KEYS[1], prefix .. 'memory', ARGV[2])
            redis.call('hincrbyfloat', KEYS[1], prefix .. 'cpu', ARGV[3])
            redis.call('hincrby', KEYS[1], prefix .. 'covered', finish - start)

            local from = tonumber(redis.call('hget', KEYS[1], prefix .. 'from'))

            if not from or start < from then
                redis.call('hset', KEYS[1], prefix .. 'from', start)
            end

            local latest = tonumber(redis.call('hget', KEYS[1], prefix .. 'until'))

            if not latest or finish > latest then
                redis.call('hset', KEYS[1], prefix .. 'until', finish)
            end

            redis.call('expire', KEYS[1], ARGV[6])
LUA;
    }
}
