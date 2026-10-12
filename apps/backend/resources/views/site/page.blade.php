@extends('site.layout')

@section('content')
    <article class="site-wrap site-section site-content">
        <h1>{{ $title }}</h1>
        {!! $body !!}
    </article>
@endsection
