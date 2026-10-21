@extends('site.layout')

@section('content')
    <div class="site-wrap site-content site-content-wide">
        @include('site.marketing.pricing.'.(in_array($locale, ['ru', 'en', 'de'], true) ? $locale : 'en'))
    </div>
@endsection
