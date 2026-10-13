@foreach ($header as $item)
    <a href="{{ $item['href'] }}" @if ($item['path'] === $path) aria-current="page" @endif>{{ $item['label'] }}</a>
@endforeach
<a href="/app/login">{{ __('site.nav.sign_in') }}</a>
<a class="site-button" href="{{ \App\Support\Site\SiteContent::url($locale, 'signup') }}">{{ __('site.nav.sign_up') }}</a>
