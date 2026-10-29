@extends('site.layout')

@section('content')
    <section class="site-wrap site-section site-narrow">
        <h1 id="form-title">{{ $title }}</h1>
        @if ($body !== null)
            <div class="site-content">{!! $body !!}</div>
        @else
            <p class="site-lead">{{ __('site.contact.lead') }}</p>
        @endif

        @if ($sent)
            <div class="site-success" role="status" tabindex="-1" autofocus aria-labelledby="contact-sent-title" data-contact="sent">
                <h2 id="contact-sent-title">{{ __('site.contact.sent_title') }}</h2>
                <p>{{ __('site.contact.sent_body') }}</p>
            </div>
        @else
        @include('site.partials.form-errors', ['errorFields' => ['name' => 'contact-name', 'email' => 'contact-email', 'company' => 'contact-company', 'message' => 'contact-message', 'attachment' => 'contact-attachment', 'captcha_answer' => 'contact-captcha'], 'attachmentAgain' => $attachment['enabled']])

            <form method="post" action="{{ \App\Support\Site\SiteContent::url($locale, 'contact') }}" class="site-form site-card" data-form="contact" enctype="multipart/form-data" aria-labelledby="form-title" data-submitting="{{ __('site.forms.submitting') }}">
                @csrf
                <p class="site-muted site-small">{{ __('site.forms.required_hint') }}</p>
                <div class="site-field">
                    <label for="contact-name">{{ __('site.contact.name') }}</label>
                    <input id="contact-name" name="name" type="text" autocomplete="name" maxlength="120" required value="{{ old('name') }}" @error('name') aria-invalid="true" aria-describedby="contact-name-error" @enderror>
                    @error('name')<p class="site-field-error" id="contact-name-error">{{ $message }}</p>@enderror
                </div>
                <div class="site-field">
                    <label for="contact-email">{{ __('site.contact.email') }}</label>
                    <input id="contact-email" name="email" type="email" autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="254" required value="{{ old('email') }}" @error('email') aria-invalid="true" aria-describedby="contact-email-error" @enderror>
                    @error('email')<p class="site-field-error" id="contact-email-error">{{ $message }}</p>@enderror
                </div>
                <div class="site-field">
                    <label for="contact-company">{{ __('site.contact.company') }}</label>
                    <input id="contact-company" name="company" type="text" autocomplete="organization" maxlength="160" value="{{ old('company') }}" @error('company') aria-invalid="true" aria-describedby="contact-company-error" @enderror>
                    @error('company')<p class="site-field-error" id="contact-company-error">{{ $message }}</p>@enderror
                </div>
                <div class="site-field">
                    <label for="contact-message">{{ __('site.contact.message') }}</label>
                    <textarea id="contact-message" name="message" rows="6" minlength="10" maxlength="5000" required @error('message') aria-invalid="true" aria-describedby="contact-message-error" @enderror>{{ old('message') }}</textarea>
                    @error('message')<p class="site-field-error" id="contact-message-error">{{ $message }}</p>@enderror
                </div>
                @if ($attachment['enabled'])
                    <div class="site-field">
                        <label for="contact-attachment">{{ __('site.contact.attachment') }}</label>
                        <input id="contact-attachment" name="attachment" type="file" data-max-bytes="{{ $attachment['max_bytes'] }}" data-size-error="{{ __('site.contact.attachment_errors.media_too_large', ['mb' => $attachment['max_mb']]) }}" accept="{{ $attachment['accept'] }}" aria-describedby="contact-attachment-hint @error('attachment') contact-attachment-error @enderror" @error('attachment') aria-invalid="true" @enderror>
                        <p class="site-muted site-small" id="contact-attachment-hint">{{ __('site.contact.attachment_hint', ['formats' => $attachment['formats'], 'mb' => $attachment['max_mb']]) }}</p>
                        @error('attachment')<p class="site-field-error" id="contact-attachment-error">{{ $message }}</p>@enderror
                    </div>
                @endif
                <div class="site-field">
                    <label for="contact-captcha">{{ __('site.contact.captcha') }}</label>
                    <div class="site-captcha-row">
                        <img src="{{ $captcha_url }}" alt="{{ __('site.contact.captcha_alt') }}" width="156" height="54">
                        <input id="contact-captcha" name="captcha_answer" type="number" inputmode="numeric" min="0" max="99" required autocomplete="off" @error('captcha_answer') aria-invalid="true" @enderror aria-describedby="contact-captcha-hint @error('captcha_answer') contact-captcha-error @enderror">
                    </div>
                    <button type="button" class="site-text-button" hidden data-captcha-text="{{ $captcha_text_url }}" data-error="{{ __('site.forms.captcha_unavailable') }}" aria-controls="captcha-text" aria-expanded="false">{{ __('site.forms.captcha_text') }}</button>
                    <p id="captcha-text" class="site-note" role="status" hidden></p>
                    <noscript><a href="{{ $captcha_text_url }}" target="_blank" rel="noopener">{{ __('site.forms.captcha_text_tab') }}</a></noscript>
                    @error('captcha_answer')<p class="site-field-error" id="contact-captcha-error">{{ $message }}</p>@enderror
                    <p id="contact-captcha-hint" class="site-muted site-small">{{ __('site.contact.captcha_hint') }}</p>
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

@push('scripts')
    @if ($formsScript = \App\Support\Site\SiteAssets::formsScript())
        <script type="module" nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ $formsScript }}"></script>
    @endif
@endpush
