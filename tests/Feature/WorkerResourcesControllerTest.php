<?php

namespace BoringO11y\HorizonWorkerStats\Tests\Feature;

use BoringO11y\HorizonWorkerStats\Contracts\WorkerResourcesRepository;
use BoringO11y\HorizonWorkerStats\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\Http\Middleware\Authenticate;
use Laravel\Sentinel\Http\Middleware\SentinelMiddleware;

class WorkerResourcesControllerTest extends TestCase
{
    public function test_worker_memory_and_cpu_are_returned_on_the_bucket_timeline()
    {
        // History is bucketed on the wall clock, so the write and the read are
        // pinned to one instant.
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        resolve(WorkerResourcesRepository::class)->record(
            'host:supervisor-1', $now->getTimestamp() - 10, $now->getTimestamp(), 64 * 1048576, 5
        );

        $response = $this->getJson('/horizon/api/worker-stats');

        $response->assertOk();

        $this->assertCount(96, $response->json('labels'));
        $this->assertSameSize($response->json('labels'), $response->json('memory'));
        $this->assertSameSize($response->json('labels'), $response->json('cpu'));
        $this->assertContains(64 * 1048576, $response->json('memory'));
    }

    public function test_the_endpoint_is_behind_horizons_gate()
    {
        Horizon::auth(fn () => false);

        $this->getJson('/horizon/api/worker-stats')->assertForbidden();
    }

    public function test_the_endpoint_runs_through_horizons_own_middleware_group()
    {
        $route = Route::getRoutes()->getByName('horizon-worker-stats.index');

        $middleware = app('router')->gatherRouteMiddleware($route);

        // Horizon's group is what adds Sentinel ahead of the configured
        // middleware; only releases that define it can be expected to apply it.
        if (app('router')->hasMiddlewareGroup('horizon')) {
            $this->assertContains(SentinelMiddleware::class.':horizon', $middleware);
        }

        $this->assertContains(Authenticate::class, $middleware);
    }
}
