@extends('site.layout')

@section('content')
    @if (str_starts_with(ltrim($body), '<div class="bw-page"'))
        <div class="site-wrap site-content site-content-wide">{!! $body !!}</div>
    @else
        <article class="site-wrap site-section site-content">
            <h1>{{ $title }}</h1>
            {!! $body !!}
        </article>
    @endif
@endsection
