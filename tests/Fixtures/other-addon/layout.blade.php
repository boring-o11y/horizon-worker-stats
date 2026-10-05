{{-- Stands in for another add-on that overrides horizon::layout the same way. --}}
{!! str_replace('</body>', '<!-- other-addon --></body>', view('other-addon-original::layout', $__data)->render()) !!}
