@if ($greeting !== null)
{!! $greeting !!}

@endif
{!! $body !!}

--
@if ($reason !== '')
{!! $reason !!}@if ($settings !== null) {!! $settings['label'] !!}: {!! $settings['url'] !!}@endif

@endif
@foreach ($links as $link)
{!! $link['label'] !!}: {!! $link['url'] !!}
@endforeach
@if ($provider !== '')
{!! $provider !!}
@endif
