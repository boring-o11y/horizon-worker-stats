<?php

namespace BoringO11y\HorizonWorkerStats\Tests\Feature;

use BoringO11y\HorizonWorkerStats\LayoutDecorator;
use BoringO11y\HorizonWorkerStats\Tests\TestCase;
use Illuminate\Support\Facades\Log;

class DashboardTest extends TestCase
{
    public function test_the_dashboard_still_renders_horizons_own_layout()
    {
        $html = $this->get('horizon')->assertOk()->getContent();

        $this->assertStringContainsString('<div id="horizon"', $html);
        $this->assertStringContainsString('<router-view></router-view>', $html);
        $this->assertStringContainsString('window.Horizon =', $html);
    }

    public function test_it_adds_the_mount_the_sidebar_link_and_the_script()
    {
        $html = $this->get('horizon')->assertOk()->getContent();

        $this->assertStringContainsString('<div id="hws-page"></div>', $html);
        $this->assertStringContainsString('data-hws-nav>', $html);
        $this->assertStringContainsString('>Worker Stats</span>', $html);
        $this->assertStringContainsString('window.HorizonWorkerStats =', $html);
        $this->assertStringContainsString('function drawChart', $html);
        $this->assertStringContainsString('#hws-page .hws-chart', $html);
    }

    public function test_the_mount_sits_after_horizons_router_outlet()
    {
        $html = $this->get('horizon')->assertOk()->getContent();

        $this->assertGreaterThan(
            strpos($html, '<router-view></router-view>'),
            strpos($html, '<div id="hws-page"></div>')
        );
    }

    public function test_the_settings_point_at_the_configured_path_and_endpoint()
    {
        config(['horizon-worker-stats.path' => 'fleet', 'horizon-worker-stats.label' => 'Fleet']);

        $html = $this->get('horizon')->assertOk()->getContent();

        $this->assertStringContainsString('/horizon/fleet"', $html);
        $this->assertStringContainsString('>Fleet</span>', $html);
        $this->assertStringContainsString('/horizon/api/worker-stats', str_replace('\\', '', $html));
    }

    public function test_the_package_path_renders_the_dashboard_shell()
    {
        // Horizon's own catch-all serves the SPA for this path, which is what
        // puts the empty router outlet and this package's mount on screen.
        $html = $this->get('horizon/worker-stats')->assertOk()->getContent();

        $this->assertStringContainsString('<div id="hws-page"></div>', $html);
    }

    public function test_it_chains_with_another_addon_that_overrides_the_layout()
    {
        // Another add-on booting after this one aliases whatever "horizon"
        // resolves to by then (this package's override) and prepends its own.
        $finder = app('view')->getFinder();
        $finder->addNamespace('other-addon-original', $finder->getHints()['horizon']);
        $finder->prependNamespace('horizon', __DIR__.'/../Fixtures/other-addon');

        $html = $this->get('horizon')->assertOk()->getContent();

        $this->assertStringContainsString('<!-- other-addon -->', $html);
        $this->assertStringContainsString('<div id="hws-page"></div>', $html);
        $this->assertStringContainsString('window.Horizon =', $html);
    }

    public function test_a_missing_anchor_is_skipped_rather_than_fatal()
    {
        Log::spy();

        $decorated = app(LayoutDecorator::class)->decorate(
            '<html><body><div id="horizon">Horizon moved its markup</div></body></html>'
        );

        // The one anchor that is still there is still patched.
        $this->assertStringContainsString('window.HorizonWorkerStats =', $decorated);
        $this->assertStringNotContainsString('<div id="hws-page"></div>', $decorated);
        $this->assertStringNotContainsString('data-hws-nav>', $decorated);

        Log::shouldHaveReceived('warning')->twice();
    }
}
