<?php

namespace BoringO11y\HorizonWorkerStats\Tests\Feature;

use BoringO11y\HorizonWorkerStats\Listeners\RecordWorkerResources;
use BoringO11y\HorizonWorkerStats\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Events\SupervisorLooped;

class DisabledTest extends TestCase
{
    protected array $overrides = ['horizon-worker-stats.enabled' => false];

    public function test_nothing_is_added_to_the_dashboard()
    {
        $html = $this->get('horizon')->assertOk()->getContent();

        $this->assertStringNotContainsString('hws-page', $html);
        $this->assertStringNotContainsString('window.HorizonWorkerStats', $html);

        // Horizon's catch-all answers every path under its prefix, so "gone"
        // is a route that was never declared rather than a 404.
        $this->assertFalse(Route::has('horizon-worker-stats.index'));
    }

    public function test_supervisors_are_not_sampled()
    {
        $listeners = collect(Event::getRawListeners()[SupervisorLooped::class] ?? []);

        $this->assertFalse($listeners->contains(RecordWorkerResources::class));
    }

    public function test_the_history_can_still_be_cleared()
    {
        $this->artisan('horizon-worker-stats:clear')->assertSuccessful();
    }
}
