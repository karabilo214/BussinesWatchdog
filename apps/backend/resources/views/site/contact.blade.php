@extends('site.layout')

@section('content')
    <section class="site-wrap site-section site-narrow">
        <h1>{{ $title }}</h1>
        @if ($body !== null)
            <div class="site-content">{!! $body !!}</div>
        @else
            <p class="site-lead">{{ __('site.contact.lead') }}</p>
        @endif

        @if ($sent)
            <div class="site-success" role="status" data-contact="sent">
                <h2>{{ __('site.contact.sent_title') }}</h2>
                <p>{{ __('site.contact.sent_body') }}</p>
            </div>
        @else
            @if ($errors->any())
                <div class="site-errors" role="alert" tabindex="-1" id="form-errors">
                    <p>{{ __('site.contact.errors') }}</p>
                    <ul>
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form method="post" action="{{ \App\Support\Site\SiteContent::url($locale, 'contact') }}" class="site-form site-card" data-form="contact" novalidate>
                @csrf
                <div class="site-field">
                    <label for="contact-name">{{ __('site.contact.name') }}</label>
                    <input id="contact-name" name="name" type="text" autocomplete="name" maxlength="120" required value="{{ old('name') }}" @error('name') aria-invalid="true" aria-describedby="contact-name-error" @enderror>
                    @error('name')<p class="site-field-error" id="contact-name-error">{{ $message }}</p>@enderror
                </div>
                <div class="site-field">
                    <label for="contact-email">{{ __('site.contact.email') }}</label>
                    <input id="contact-email" name="email" type="email" autocomplete="email" maxlength="254" required value="{{ old('email') }}" @error('email') aria-invalid="true" aria-describedby="contact-email-error" @enderror>
                    @error('email')<p class="site-field-error" id="contact-email-error">{{ $message }}</p>@enderror
                </div>
                <div class="site-field">
                    <label for="contact-company">{{ __('site.contact.company') }}</label>
                    <input id="contact-company" name="company" type="text" autocomplete="organization" maxlength="160" value="{{ old('company') }}">
                </div>
                <div class="site-field">
                    <label for="contact-message">{{ __('site.contact.message') }}</label>
                    <textarea id="contact-message" name="message" rows="6" maxlength="5000" required @error('message') aria-invalid="true" aria-describedby="contact-message-error" @enderror>{{ old('message') }}</textarea>
                    @error('message')<p class="site-field-error" id="contact-message-error">{{ $message }}</p>@enderror
                </div>
                <div class="site-trap" aria-hidden="true">
                    <label for="contact-website">{{ __('site.contact.website') }}</label>
                    <input id="contact-website" name="website" type="text" tabindex="-1" autocomplete="off">
                </div>
                <p class="site-muted site-small">
                    {{ __('site.contact.privacy') }}
                    @if ($privacy)
                        <a href="{{ $privacy }}">{{ __('site.contact.privacy_link') }}</a>
                    @endif
                </p>
                <p><button type="submit" class="site-button">{{ __('site.contact.submit') }}</button></p>
            </form>
        @endif
    </section>
@endsection
