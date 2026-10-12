@foreach ($header as $item)
    <a href="{{ $item['href'] }}" @if ($item['path'] === $path) aria-current="page" @endif>{{ $item['label'] }}</a>
@endforeach
<a class="site-button" href="/app/login">{{ __('site.nav.sign_in') }}</a>
