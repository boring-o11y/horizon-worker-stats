<?php

namespace BoringO11y\HorizonWorkerStats\Tests\Feature;

use BoringO11y\HorizonWorkerStats\Contracts\WorkerResourcesRepository;
use BoringO11y\HorizonWorkerStats\Tests\TestCase;
use Carbon\CarbonImmutable;

class ClearCommandTest extends TestCase
{
    public function test_it_deletes_the_history()
    {
        $now = CarbonImmutable::now()->getTimestamp();
        $resources = resolve(WorkerResourcesRepository::class);

        $resources->record('host:supervisor-1', $now - 10, $now, 1048576, 1);

        $this->artisan('horizon-worker-stats:clear')->assertSuccessful();

        $this->assertSame([], array_filter($resources->trends()['memory'], fn ($value) => $value !== null));
    }
}
