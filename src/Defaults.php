<?php

namespace BoringO11y\HorizonWorkerStats;

class Defaults
{
    /**
     * The package's configuration file, once read.
     *
     * @var array<string, mixed>|null
     */
    private static $defaults = null;

    /**
     * Get the default for one of the package's settings.
     *
     * The values come from the package's own configuration file, so they are
     * written down once. They are still needed beside mergeConfigFrom(): it is
     * skipped when the configuration is cached, so a cache built before the
     * package was installed has none of its keys.
     *
     * @param  string  $key
     * @return mixed
     */
    public static function get($key)
    {
        self::$defaults ??= require __DIR__.'/../config/horizon-worker-stats.php';

        return self::$defaults[$key] ?? null;
    }
}
