<?php

namespace BoringO11y\HorizonWorkerStats\Tests\Feature;

use BoringO11y\HorizonWorkerStats\Contracts\WorkerResourcesRepository;
use BoringO11y\HorizonWorkerStats\Listeners\RecordWorkerResources;
use BoringO11y\HorizonWorkerStats\ProcessResources;
use BoringO11y\HorizonWorkerStats\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\Supervisor;
use Laravel\Horizon\SupervisorOptions;
use Laravel\Horizon\WorkerProcess;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

class WorkerResourcesTest extends TestCase
{
    /**
     * The start of the bucket the tests write into.
     *
     * @var int
     */
    protected $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        // Buckets are aligned to the wall clock, so everything is pinned to a
        // known instant inside one: the default interval is fifteen minutes.
        $this->bucket = intdiv(CarbonImmutable::now()->getTimestamp(), 900) * 900 - 900;

        $this->travelToSecond($this->bucket + 900 + 300);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_memory_and_cpu_are_averaged_over_the_time_covered()
    {
        $resources = resolve(WorkerResourcesRepository::class);

        // 200MB held and 450 CPU seconds used across the whole bucket: an
        // average of 200MB and half a core.
        $resources->record('host:supervisor-1', $this->bucket, $this->bucket + 450, 100 * 1048576, 225);
        $resources->record('host:supervisor-1', $this->bucket + 450, $this->bucket + 900, 300 * 1048576, 225);

        $trends = $resources->trends();
        $index = array_search($this->bucket, $trends['labels'], true);

        $this->assertSame(200 * 1048576, $trends['memory'][$index]);
        $this->assertSame(0.5, $trends['cpu'][$index]);
    }

    public function test_supervisors_add_up_into_one_total()
    {
        $resources = resolve(WorkerResourcesRepository::class);

        $resources->record('host-1:supervisor-1', $this->bucket, $this->bucket + 900, 100 * 1048576, 900);
        $resources->record('host-2:supervisor-1', $this->bucket, $this->bucket + 900, 50 * 1048576, 450);

        $trends = $resources->trends();
        $index = array_search($this->bucket, $trends['labels'], true);

        $this->assertSame(150 * 1048576, $trends['memory'][$index]);
        $this->assertSame(1.5, $trends['cpu'][$index]);
    }

    public function test_a_supervisor_whose_latest_sample_lags_is_not_read_as_idle()
    {
        $resources = resolve(WorkerResourcesRepository::class);

        // Both hold 1GB on one core, sampling five seconds out of step. Twelve
        // seconds into the bucket the second has only written up to :05.
        $resources->record('host-1:supervisor-1', $this->bucket, $this->bucket + 10, 1024 * 1048576, 10);
        $resources->record('host-2:supervisor-1', $this->bucket, $this->bucket + 5, 1024 * 1048576, 5);

        $trends = $resources->trends();
        $index = array_search($this->bucket, $trends['labels'], true);

        $this->assertSame(2048 * 1048576, $trends['memory'][$index]);
        $this->assertSame(2.0, $trends['cpu'][$index]);
    }

    public function test_a_gap_between_samples_is_not_read_as_idle()
    {
        $resources = resolve(WorkerResourcesRepository::class);

        // The second supervisor's loop stalled for most of the bucket, so the
        // listener reset its baseline instead of recording across the stall.
        $resources->record('host-1:supervisor-1', $this->bucket, $this->bucket + 900, 100 * 1048576, 900);
        $resources->record('host-2:supervisor-1', $this->bucket, $this->bucket + 100, 100 * 1048576, 100);
        $resources->record('host-2:supervisor-1', $this->bucket + 800, $this->bucket + 900, 100 * 1048576, 100);

        $trends = $resources->trends();
        $index = array_search($this->bucket, $trends['labels'], true);

        $this->assertSame(200 * 1048576, $trends['memory'][$index]);
        $this->assertSame(2.0, $trends['cpu'][$index]);
    }

    public function test_a_supervisor_running_for_part_of_a_bucket_counts_for_that_part()
    {
        $resources = resolve(WorkerResourcesRepository::class);

        // The second machine was removed half way through the bucket.
        $resources->record('host-1:supervisor-1', $this->bucket, $this->bucket + 900, 100 * 1048576, 900);
        $resources->record('host-2:supervisor-1', $this->bucket, $this->bucket + 450, 100 * 1048576, 450);

        $trends = $resources->trends();
        $index = array_search($this->bucket, $trends['labels'], true);

        $this->assertSame(150 * 1048576, $trends['memory'][$index]);
        $this->assertSame(1.5, $trends['cpu'][$index]);
    }

    public function test_a_span_across_a_boundary_is_split_between_buckets()
    {
        $resources = resolve(WorkerResourcesRepository::class);

        // Ten seconds, the last two of which fall into the next bucket.
        $resources->record('host:supervisor-1', $this->bucket + 892, $this->bucket + 902, 100 * 1048576, 10);

        $trends = $resources->trends();
        $first = array_search($this->bucket, $trends['labels'], true);

        $this->assertSame(100 * 1048576, $trends['memory'][$first]);
        $this->assertSame(1.0, $trends['cpu'][$first]);
        $this->assertSame(100 * 1048576, $trends['memory'][$first + 1]);
        $this->assertSame(1.0, $trends['cpu'][$first + 1]);
    }

    public function test_a_bucket_nothing_sampled_is_null_rather_than_zero()
    {
        $resources = resolve(WorkerResourcesRepository::class);

        $resources->record('host:supervisor-1', $this->bucket, $this->bucket + 10, 0, 0);

        $trends = $resources->trends();
        $index = array_search($this->bucket, $trends['labels'], true);

        // A fleet with no workers really did use nothing...
        $this->assertSame(0, $trends['memory'][$index]);

        // ...which is not the same as a bucket nobody measured.
        $this->assertNull($trends['memory'][$index - 1]);
        $this->assertNull($trends['cpu'][$index - 1]);
        $this->assertSameSize($trends['labels'], $trends['memory']);
    }

    public function test_the_history_is_broken_down_by_supervisor_across_machines()
    {
        $resources = resolve(WorkerResourcesRepository::class);

        $resources->record('host-1-abcd:emails', $this->bucket, $this->bucket + 900, 100 * 1048576, 900);
        $resources->record('host-2-efgh:emails', $this->bucket, $this->bucket + 900, 50 * 1048576, 450);
        $resources->record('host-1-abcd:reports', $this->bucket, $this->bucket + 900, 300 * 1048576, 1800);

        $trends = $resources->trends();
        $index = array_search($this->bucket, $trends['labels'], true);
        $supervisors = array_column($trends['supervisors'], null, 'name');

        $this->assertSame(['emails', 'reports'], array_column($trends['supervisors'], 'name'));
        $this->assertSame(150 * 1048576, $supervisors['emails']['memory'][$index]);
        $this->assertSame(1.5, $supervisors['emails']['cpu'][$index]);
        $this->assertSame(300 * 1048576, $supervisors['reports']['memory'][$index]);
        $this->assertSame(2.0, $supervisors['reports']['cpu'][$index]);

        $this->assertSame(450 * 1048576, $trends['memory'][$index]);
        $this->assertSame(3.5, $trends['cpu'][$index]);
    }

    public function test_a_restarted_supervisor_keeps_one_series()
    {
        $resources = resolve(WorkerResourcesRepository::class);

        // Horizon was restarted half way through the bucket, which gives the
        // master, and so every supervisor under it, a new name.
        $resources->record('host-abcd:emails', $this->bucket, $this->bucket + 450, 100 * 1048576, 450);
        $resources->record('host-wxyz:emails', $this->bucket + 450, $this->bucket + 900, 100 * 1048576, 450);

        $trends = $resources->trends();
        $index = array_search($this->bucket, $trends['labels'], true);

        $this->assertCount(1, $trends['supervisors']);
        $this->assertSame(100 * 1048576, $trends['supervisors'][0]['memory'][$index]);
        $this->assertSame(1.0, $trends['supervisors'][0]['cpu'][$index]);
    }

    public function test_a_master_name_holding_a_colon_still_groups_by_supervisor()
    {
        $resources = resolve(WorkerResourcesRepository::class);

        // A custom MasterSupervisor::determineNameUsing() resolver may put a
        // colon in the master's part of the name.
        $resources->record('app:prod-abcd:emails', $this->bucket, $this->bucket + 450, 100 * 1048576, 450);
        $resources->record('app:prod-wxyz:emails', $this->bucket + 450, $this->bucket + 900, 100 * 1048576, 450);

        $this->assertSame(['emails'], array_column(resolve(WorkerResourcesRepository::class)->trends()['supervisors'], 'name'));
    }

    public function test_a_supervisor_missing_from_a_sampled_bucket_is_zero_rather_than_null()
    {
        $resources = resolve(WorkerResourcesRepository::class);

        $resources->record('host:emails', $this->bucket - 900, $this->bucket, 100 * 1048576, 900);
        $resources->record('host:emails', $this->bucket, $this->bucket + 900, 100 * 1048576, 900);
        $resources->record('host:reports', $this->bucket, $this->bucket + 900, 100 * 1048576, 900);

        $trends = $resources->trends();
        $index = array_search($this->bucket, $trends['labels'], true);
        $reports = array_column($trends['supervisors'], null, 'name')['reports'];

        // Not deployed yet while the other supervisor was sampled...
        $this->assertSame(0, $reports['memory'][$index - 1]);
        $this->assertSame(0.0, $reports['cpu'][$index - 1]);

        // ...which is not the same as a bucket nobody measured.
        $this->assertNull($reports['memory'][$index - 2]);
        $this->assertSameSize($trends['labels'], $reports['memory']);
    }

    public function test_clearing_removes_the_history()
    {
        $resources = resolve(WorkerResourcesRepository::class);

        $resources->record('host:supervisor-1', $this->bucket, $this->bucket + 900, 1048576, 1);
        $resources->clear();

        $this->assertSame([], array_filter($resources->trends()['memory'], fn ($value) => $value !== null));
    }

    public function test_the_listener_records_the_span_between_two_samples()
    {
        [$listener, $processes] = $this->listener([101, 102]);

        $processes->shouldReceive('sample')->with([101, 102])->twice()->andReturn(
            [101 => ['memory' => 100 * 1048576, 'cpu' => 5.0], 102 => ['memory' => 100 * 1048576, 'cpu' => 1.0]],
            [101 => ['memory' => 200 * 1048576, 'cpu' => 10.0], 102 => ['memory' => 200 * 1048576, 'cpu' => 6.0]],
        );

        $this->travelToSecond($this->bucket + 100);
        $listener->handle($this->looped());

        // Nothing to measure a span against yet.
        $this->assertNull($this->bucketTrend()['memory']);

        // Within the sample interval, so the sampler is not asked again.
        $this->travelToSecond($this->bucket + 105);
        $listener->handle($this->looped());

        $this->travelToSecond($this->bucket + 110);
        $listener->handle($this->looped());

        $trend = $this->bucketTrend();

        // The average of a 200MB and a 400MB fleet, and 10 CPU seconds over 10.
        $this->assertSame(300 * 1048576, $trend['memory']);
        $this->assertSame(1.0, $trend['cpu']);
    }

    public function test_a_worker_started_between_samples_counts_all_of_its_cpu()
    {
        [$listener, $processes] = $this->listener([101, 103]);

        $processes->shouldReceive('sample')->twice()->andReturn(
            [101 => ['memory' => 0, 'cpu' => 5.0]],
            [101 => ['memory' => 0, 'cpu' => 7.0], 103 => ['memory' => 0, 'cpu' => 3.0]],
        );

        $this->travelToSecond($this->bucket + 100);
        $listener->handle($this->looped());

        $this->travelToSecond($this->bucket + 110);
        $listener->handle($this->looped());

        $this->assertSame(0.5, $this->bucketTrend()['cpu']);
    }

    public function test_workers_that_cannot_be_read_are_not_recorded_as_idle()
    {
        [$listener, $processes] = $this->listener([101]);

        $processes->shouldReceive('sample')->twice()->andReturn(
            [101 => ['memory' => 1048576, 'cpu' => 1.0]],
            [],
        );

        $this->travelToSecond($this->bucket + 100);
        $listener->handle($this->looped());

        $this->travelToSecond($this->bucket + 110);
        $listener->handle($this->looped());

        $this->assertNull($this->bucketTrend()['memory']);
    }

    public function test_workers_that_cannot_be_read_are_still_sampled_at_the_interval()
    {
        [$listener, $processes] = $this->listener([101]);

        // Would shell out to a missing ps on every loop if an unreadable
        // sample also forgot when it was taken.
        $processes->shouldReceive('sample')->twice()->andReturn([]);

        $this->travelToSecond($this->bucket + 100);
        $listener->handle($this->looped());

        $this->travelToSecond($this->bucket + 101);
        $listener->handle($this->looped());

        $this->travelToSecond($this->bucket + 109);
        $listener->handle($this->looped());

        $this->travelToSecond($this->bucket + 110);
        $listener->handle($this->looped());
    }

    public function test_a_stalled_loop_is_not_interpolated_across()
    {
        [$listener, $processes] = $this->listener([101]);

        $processes->shouldReceive('sample')->twice()->andReturn(
            [101 => ['memory' => 1048576, 'cpu' => 1.0]],
            [101 => ['memory' => 1048576, 'cpu' => 2.0]],
        );

        $this->travelToSecond($this->bucket + 100);
        $listener->handle($this->looped());

        $this->travelToSecond($this->bucket + 100 + RecordWorkerResources::MAX_GAP + 1);
        $listener->handle($this->looped());

        $this->assertNull($this->bucketTrend()['memory']);
    }

    public function test_real_worker_processes_are_sampled_through_the_supervisor_loop()
    {
        $supervisor = new Supervisor(new SupervisorOptions(MasterSupervisor::name().':resources', 'redis'));

        $process = new WorkerProcess(Process::fromShellCommandline('exec sleep 30'));
        $process->start(function () {});

        $supervisor->processPools->first()->processes[] = $process;

        try {
            $this->travelToSecond($this->bucket + 100);
            event(new SupervisorLooped($supervisor));

            $this->travelToSecond($this->bucket + 110);
            event(new SupervisorLooped($supervisor));
        } finally {
            $process->process->stop(0);
        }

        // Dispatched through the event rather than calling handle(), so this
        // also covers the listener being attached, and reading real pids off
        // a stock Horizon supervisor's process pools.
        $this->assertGreaterThan(0, $this->bucketTrend()['memory']);
    }

    public function test_a_failure_is_reported_and_never_escapes_into_the_supervisor_loop()
    {
        [$listener, $processes] = $this->listener([101]);

        $processes->shouldReceive('sample')->andThrow(new RuntimeException('proc went away'));

        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with(Mockery::on(fn ($e) => $e->getMessage() === 'proc went away'));
        $this->app->instance(ExceptionHandler::class, $handler);

        $listener->handle($this->looped());

        $this->assertNull($this->bucketTrend()['memory']);
    }

    public function test_the_listener_keeps_its_samples_between_dispatches()
    {
        // The dispatcher resolves the listener on every event, so without the
        // singleton binding each loop would start without a previous sample.
        $this->assertSame(resolve(RecordWorkerResources::class), resolve(RecordWorkerResources::class));
    }

    public function test_the_retention_window_follows_the_package_config()
    {
        config(['horizon-worker-stats.interval' => 60, 'horizon-worker-stats.retention' => 6]);
        $this->app->forgetInstance(WorkerResourcesRepository::class);

        $labels = resolve(WorkerResourcesRepository::class)->trends()['labels'];

        $this->assertCount(6, $labels);
        $this->assertSame(3600, $labels[1] - $labels[0]);
    }

    /**
     * Build a listener whose supervisor reports the given worker pids.
     *
     * @param  array<int, int>  $pids
     * @return array{0: RecordWorkerResources, 1: MockInterface}
     */
    protected function listener(array $pids)
    {
        $processes = Mockery::mock(ProcessResources::class);

        $listener = new class($processes, resolve(WorkerResourcesRepository::class), $pids) extends RecordWorkerResources
        {
            public function __construct($processes, $resources, public array $fixedPids)
            {
                parent::__construct($processes, $resources);
            }

            protected function pids(Supervisor $supervisor)
            {
                return $this->fixedPids;
            }
        };

        return [$listener, $processes];
    }

    /**
     * Build a loop event for a supervisor.
     *
     * @return SupervisorLooped
     */
    protected function looped()
    {
        return new SupervisorLooped(new Supervisor(new SupervisorOptions('host:supervisor-1', 'redis')));
    }

    /**
     * Get the recorded memory and CPU for the bucket under test.
     *
     * @return array{memory: int|null, cpu: float|null}
     */
    protected function bucketTrend()
    {
        $trends = resolve(WorkerResourcesRepository::class)->trends();
        $index = array_search($this->bucket, $trends['labels'], true);

        return ['memory' => $trends['memory'][$index], 'cpu' => $trends['cpu'][$index]];
    }

    /**
     * Pin the clock to the given timestamp.
     *
     * @param  int  $timestamp
     * @return void
     */
    protected function travelToSecond($timestamp)
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp($timestamp));
    }
}
