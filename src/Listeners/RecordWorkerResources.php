<?php

namespace BoringO11y\HorizonWorkerStats\Listeners;

use BoringO11y\HorizonWorkerStats\Contracts\WorkerResourcesRepository;
use BoringO11y\HorizonWorkerStats\ProcessResources;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Horizon\Supervisor;
use Throwable;

class RecordWorkerResources
{
    /**
     * The seconds between two samples of a supervisor's workers.
     *
     * CPU is read as a running counter, so a worker that is replaced between
     * two samples loses whatever it used since the last one. Sampling often
     * keeps that small for workers restarted by max_jobs or max_time.
     *
     * @var int
     */
    const SAMPLE_INTERVAL = 10;

    /**
     * The longest gap between two samples that is still recorded as one span.
     *
     * A longer one means the loop stalled, and interpolating memory across it
     * would chart a level nobody measured.
     *
     * @var int
     */
    const MAX_GAP = 60;

    /**
     * The process resources implementation.
     *
     * @var \BoringO11y\HorizonWorkerStats\ProcessResources
     */
    public $processes;

    /**
     * The worker resources repository implementation.
     *
     * @var \BoringO11y\HorizonWorkerStats\Contracts\WorkerResourcesRepository
     */
    public $resources;

    /**
     * The previous sample, keyed by supervisor name.
     *
     * @var array<string, array{time: int, memory: int, cpu: array<int, float>}>
     */
    public $samples = [];

    /**
     * When each supervisor's workers were last sampled, keyed by supervisor name.
     *
     * Kept apart from the samples so the interval also holds when a sample is
     * discarded: a host without ps would otherwise shell out on every loop.
     *
     * @var array<string, int>
     */
    public $sampledAt = [];

    /**
     * Create a new listener instance.
     *
     * @param  \BoringO11y\HorizonWorkerStats\ProcessResources  $processes
     * @param  \BoringO11y\HorizonWorkerStats\Contracts\WorkerResourcesRepository  $resources
     * @return void
     */
    public function __construct(ProcessResources $processes, WorkerResourcesRepository $resources)
    {
        $this->processes = $processes;
        $this->resources = $resources;
    }

    /**
     * Handle the event.
     *
     * @param  \Laravel\Horizon\Events\SupervisorLooped  $event
     * @return void
     */
    public function handle(SupervisorLooped $event)
    {
        $supervisor = $event->supervisor;
        $now = CarbonImmutable::now()->getTimestamp();

        if (isset($this->sampledAt[$supervisor->name]) &&
            $now - $this->sampledAt[$supervisor->name] < static::SAMPLE_INTERVAL) {
            return;
        }

        $this->sampledAt[$supervisor->name] = $now;
        $previous = $this->samples[$supervisor->name] ?? null;

        // Runs inside the supervisor's monitor loop, so a failure here must not
        // escape and skip the rest of that tick.
        try {
            $pids = $this->pids($supervisor);
            $sample = $this->processes->sample($pids);

            // Workers exist but none could be read: ps is missing, or /proc is
            // hidden. Recording that as zero would chart a fleet using nothing.
            if (! empty($pids) && empty($sample)) {
                unset($this->samples[$supervisor->name]);

                return;
            }

            $current = [
                'time' => $now,
                'memory' => array_sum(array_column($sample, 'memory')),
                'cpu' => array_map(fn ($process) => $process['cpu'], $sample),
            ];

            $this->samples[$supervisor->name] = $current;

            if (! $previous || $now - $previous['time'] > static::MAX_GAP) {
                return;
            }

            $this->resources->record(
                $supervisor->name,
                $previous['time'],
                $now,
                ($previous['memory'] + $current['memory']) / 2,
                $this->cpuBetween($previous['cpu'], $current['cpu'])
            );
        } catch (Throwable $e) {
            app(ExceptionHandler::class)->report($e);
        }
    }

    /**
     * Get the process ids of the supervisor's running workers.
     *
     * Workers still finishing their last job after being scaled down are
     * included: they hold memory until they exit.
     *
     * The pid is read off the Symfony process, which only reports one while
     * the process runs. That is exactly the set wanted here.
     *
     * @param  \Laravel\Horizon\Supervisor  $supervisor
     * @return array<int, int>
     */
    protected function pids(Supervisor $supervisor)
    {
        return collect($supervisor->processPools)
            ->flatMap(fn ($pool) => $pool->runningProcesses())
            ->map(fn ($process) => $process->process->getPid())
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get the CPU seconds the workers used between two samples.
     *
     * A worker absent from the earlier sample started since, so all of its CPU
     * falls inside the span. A counter lower than before means the pid was
     * reused by a new worker, which is treated the same way.
     *
     * @param  array<int, float>  $before
     * @param  array<int, float>  $after
     * @return float
     */
    protected function cpuBetween(array $before, array $after)
    {
        $total = 0.0;

        foreach ($after as $pid => $cpu) {
            $total += isset($before[$pid]) && $cpu >= $before[$pid]
                ? $cpu - $before[$pid]
                : $cpu;
        }

        return $total;
    }
}
