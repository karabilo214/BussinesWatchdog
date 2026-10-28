@foreach ($header as $item)
    @if ($item['children'] === [])
        <a href="{{ $item['href'] }}" @if ($item['path'] === $path) aria-current="page" @endif>{{ $item['label'] }}</a>
    @else
        <details class="site-dropdown" data-dropdown>
            <summary @if ($item['path'] === $path || in_array($path, array_column($item['children'], 'path'), true)) aria-current="true" @endif>{{ $item['label'] }}</summary>
            <div class="site-dropdown-panel">
                <a href="{{ $item['href'] }}" @if ($item['path'] === $path) aria-current="page" @endif>{{ $item['label'] }}</a>
                @foreach ($item['children'] as $child)
                    <a href="{{ $child['href'] }}" @if ($child['path'] === $path) aria-current="page" @endif>{{ $child['label'] }}</a>
                @endforeach
            </div>
        </details>
    @endif
@endforeach
<a href="/app/login">{{ __('site.nav.sign_in') }}</a>
<a class="site-button" href="{{ \App\Support\Site\SiteContent::url($locale, 'signup') }}">{{ __('site.nav.sign_up') }}</a>
