<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When disabled the package registers nothing at all: no sampling in the
    | supervisors, no routes, no view override, no sidebar link. Horizon's
    | dashboard renders exactly as it would without the package installed.
    |
    */

    'enabled' => env('HORIZON_WORKER_STATS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Path
    |--------------------------------------------------------------------------
    |
    | The dashboard-relative path the page answers to, and the label used for
    | its sidebar link. The path must be one Horizon's own router does not
    | know about: Horizon then renders an empty <router-view> and this page
    | is the only thing in the column.
    |
    */

    'path' => 'worker-stats',

    'label' => 'Worker Stats',

    /*
    |--------------------------------------------------------------------------
    | Chart Buckets
    |--------------------------------------------------------------------------
    |
    | Each point on the charts is the average over one bucket of this many
    | minutes, and the charts cover the last "retention" hours. Every bucket
    | is one small Redis hash that expires on its own.
    |
    */

    'interval' => 15,

    'retention' => 24,

    /*
    |--------------------------------------------------------------------------
    | Poll Interval
    |--------------------------------------------------------------------------
    |
    | How often, in milliseconds, the page refreshes itself while open.
    |
    */

    'poll_interval' => 60000,

];
