{{--
    Overrides horizon::layout.

    Shipping a copy of Horizon's layout would mean re-syncing it on every
    Horizon release, so this renders the layout it replaced (Horizon's own, or
    another add-on's override of it) and splices this package's page into the
    result.

    Whatever data Horizon's HomeController passed is forwarded verbatim, so a
    Horizon release that adds a variable to its layout keeps working here.
--}}
@php
    $__hwsData = collect(get_defined_vars())
        ->reject(fn ($value, $key) => str_starts_with($key, '__') || in_array($key, ['app', 'errors', 'obLevel'], true))
        ->all();
@endphp
{!! app(\BoringO11y\HorizonWorkerStats\LayoutDecorator::class)->decorate(
    view(\BoringO11y\HorizonWorkerStats\HorizonWorkerStatsServiceProvider::ORIGINAL_NAMESPACE.'::layout', $__hwsData)->render()
) !!}
