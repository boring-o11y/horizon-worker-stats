{{-- Stands in for another add-on that overrides horizon::layout the same way. --}}
{!! str_replace('</body>', '<!-- other-addon --></body>', view('other-addon-original::layout', collect(get_defined_vars())->reject(fn ($v, $k) => str_starts_with($k, '__') || in_array($k, ['app', 'errors', 'obLevel'], true))->all())->render()) !!}
