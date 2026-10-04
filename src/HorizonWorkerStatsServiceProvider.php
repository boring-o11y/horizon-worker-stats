<?php

namespace BoringO11y\HorizonWorkerStats;

use BoringO11y\HorizonWorkerStats\Console\ClearCommand;
use BoringO11y\HorizonWorkerStats\Contracts\WorkerResourcesRepository;
use BoringO11y\HorizonWorkerStats\Listeners\RecordWorkerResources;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Horizon\Http\Middleware\Authenticate;

class HorizonWorkerStatsServiceProvider extends ServiceProvider
{
    /**
     * The view namespace Horizon's layout is reachable under once overridden.
     *
     * It is this package's own rather than a shared "horizon-original": another
     * add-on taking over the same layout aliases whatever "horizon" resolves to
     * when it boots, so each one rendering its own alias chains them together
     * in either boot order instead of the later one skipping the earlier.
     */
    public const ORIGINAL_NAMESPACE = 'horizon-worker-stats-original';

    /**
     * The middleware group the package's routes run through.
     */
    public const MIDDLEWARE_GROUP = 'horizon-worker-stats';

    /**
     * Register the package's services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/horizon-worker-stats.php', 'horizon-worker-stats');

        // The bindings are registered unconditionally: they are lazy, so an
        // installation that has the package turned off never resolves them.
        $this->app->singleton(WorkerResourcesRepository::class, RedisWorkerResourcesRepository::class);
        $this->app->singleton(ProcessResources::class);
        $this->app->singleton(LayoutDecorator::class);

        // A singleton so the previous sample and the throttle survive between
        // loops: the event dispatcher resolves a listener on every dispatch.
        $this->app->singleton(RecordWorkerResources::class);

        // Routes are registered from a booting callback rather than from boot():
        // by then every provider has registered, so Horizon's config is merged,
        // but no provider has booted, so Horizon has not yet declared the
        // catch-all route that would otherwise swallow these paths.
        $this->app->isBooted()
            ? $this->registerRoutes()
            : $this->app->booting(fn () => $this->registerRoutes());
    }

    /**
     * Bootstrap the package.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/horizon-worker-stats.php' => $this->app->configPath('horizon-worker-stats.php'),
            ], 'horizon-worker-stats-config');

            $this->commands([ClearCommand::class]);
        }

        if (! $this->enabled()) {
            return;
        }

        // Supervisors are console processes, and SupervisorLooped is only ever
        // fired inside one, so a web request has no use for the listener.
        if ($this->app->runningInConsole()) {
            Event::listen(SupervisorLooped::class, RecordWorkerResources::class);
        }

        // Deferred to after every provider has booted: Horizon registers its
        // own view namespace in its boot(), and the override has to find that
        // namespace in order to alias it and render the real layout from it.
        $this->app->booted(function () {
            $this->registerMiddlewareGroup();

            $this->callAfterResolving('view', fn ($view) => $this->registerViewOverride($view));
        });
    }

    /**
     * Register the package's routes inside Horizon's own group.
     *
     * @return void
     */
    protected function registerRoutes()
    {
        if (! $this->enabled()) {
            return;
        }

        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'domain' => config('horizon.domain', null),
            'prefix' => config('horizon.path'),
            'middleware' => self::MIDDLEWARE_GROUP,
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/worker-stats.php');
        });
    }

    /**
     * Define the middleware group these routes run through.
     *
     * Horizon 5.45 and later put their routes behind a "horizon" group that
     * adds Sentinel's authorization ahead of the configured middleware, so
     * that group is used whenever Horizon defines it; older releases route
     * through the configured middleware alone. Either way the Authenticate
     * middleware Horizon applies through its base controller comes after.
     *
     * This runs once every provider has booted, as Horizon defines its group
     * in its own boot(); the routes only name the group, which the router
     * resolves per request.
     *
     * @return void
     */
    protected function registerMiddlewareGroup()
    {
        $router = $this->app['router'];

        $horizon = $router->hasMiddlewareGroup('horizon')
            ? ['horizon']
            : (array) config('horizon.middleware', 'web');

        $router->middlewareGroup(self::MIDDLEWARE_GROUP, array_values(array_unique(array_merge(
            $horizon, [Authenticate::class]
        ))));
    }

    /**
     * Take over the horizon::layout view, keeping the one it replaces reachable.
     *
     * Whatever "horizon" resolves to right now is aliased, which is Horizon's
     * own views or another add-on's override of them, so the override renders
     * the real layout instead of a copy that would need re-syncing on every
     * Horizon release.
     *
     * @param  Factory  $view
     * @return void
     */
    protected function registerViewOverride($view)
    {
        /** @var FileViewFinder $finder */
        $finder = $view->getFinder();
        $hints = $finder->getHints();

        if (! isset($hints['horizon']) || isset($hints[self::ORIGINAL_NAMESPACE])) {
            return;
        }

        $finder->addNamespace(self::ORIGINAL_NAMESPACE, $hints['horizon']);
        $finder->prependNamespace('horizon', __DIR__.'/../resources/views');
    }

    /**
     * @return bool
     */
    protected function enabled()
    {
        return (bool) config('horizon-worker-stats.enabled', true);
    }
}
