<?php

namespace BoringO11y\HorizonWorkerStats\Contracts;

interface WorkerResourcesRepository
{
    /**
     * Record what one supervisor's workers used between two samples.
     *
     * Every supervisor records into the same buckets, so the history is the
     * total across all workers on every machine.
     *
     * @param  string  $supervisor
     * @param  int  $from
     * @param  int  $until
     * @param  float  $memory  The average resident memory held across the span, in bytes.
     * @param  float  $cpu  The CPU seconds consumed across the span.
     * @return void
     */
    public function record($supervisor, $from, $until, $memory, $cpu);

    /**
     * Get the average memory and CPU cores in use across the retention window.
     *
     * A bucket nothing was sampled in is null rather than zero. The same
     * history is also broken down by supervisor name, across every machine.
     *
     * @return array{labels: array<int, int>, memory: array<int, int|null>, cpu: array<int, float|null>, supervisors: array<int, array{name: string, memory: array<int, int|null>, cpu: array<int, float|null>}>}
     */
    public function trends();

    /**
     * Delete all stored resource information.
     *
     * @return void
     */
    public function clear();
}
