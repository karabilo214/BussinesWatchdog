@extends('site.layout')

@section('content')
    <section class="site-wrap site-section site-narrow">
        <h1>{{ $title }}</h1>
        <p class="site-lead">{{ __('site.signup.lead') }}</p>
        @if ($connect_site)
            <p class="site-note" data-plugin-connect>{{ __('site.signup.plugin_connect', ['site' => $connect_site]) }}</p>
        @endif

        @if ($errors->any())
            <div class="site-errors" role="alert" id="form-errors">
                <p>{{ __('site.contact.errors') }}</p>
                <ul>
                    @foreach ($errors->all() as $message)
                        <li>{!! nl2br(e($message)) !!}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ \App\Support\Site\SiteContent::url($locale, 'signup') }}" class="site-form site-card" data-form="signup" novalidate>
            @csrf
            <input type="hidden" name="plan" value="{{ $plan }}">
            <input type="hidden" name="price" value="{{ $price }}">
            <input type="hidden" name="stores" value="{{ $stores }}">
            <div class="site-field">
                <label for="signup-salutation">{{ __('site.signup.salutation') }} <span class="site-muted">({{ __('site.signup.optional') }})</span></label>
                <select id="signup-salutation" name="salutation" autocomplete="honorific-prefix" @error('salutation') aria-invalid="true" aria-describedby="signup-salutation-error" @enderror>
                    <option value="" @selected((string) old('salutation') === '')>{{ __('site.signup.salutations.none') }}</option>
                    @foreach (\App\Models\User::SALUTATIONS as $salutation)
                        <option value="{{ $salutation }}" @selected(old('salutation') === $salutation)>{{ __('site.signup.salutations.'.$salutation) }}</option>
                    @endforeach
                </select>
                @error('salutation')<p class="site-field-error" id="signup-salutation-error">{{ $message }}</p>@enderror
            </div>
            @foreach ([['first_name', 'text', 'given-name', 100, false], ['last_name', 'text', 'family-name', 100, true], ['company_name', 'text', 'organization', 150, false], ['organization_name', 'text', 'off', 100, true], ['email', 'email', 'email', 255, true]] as [$field, $type, $autocomplete, $max, $required])
                <div class="site-field">
                    <label for="signup-{{ $field }}">{{ __('site.signup.'.($field === 'organization_name' ? 'organization' : $field)) }}@unless ($required) <span class="site-muted">({{ __('site.signup.optional') }})</span>@endunless</label>
                    <input id="signup-{{ $field }}" name="{{ $field }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}" maxlength="{{ $max }}" @if ($required) required @endif value="{{ old($field) }}" @error($field) aria-invalid="true" aria-describedby="signup-{{ $field }}-error" @enderror>
                    @error($field)<p class="site-field-error" id="signup-{{ $field }}-error">{{ $message }}</p>@enderror
                </div>
            @endforeach
            <div class="site-field">
                <label for="signup-password">{{ __('site.signup.password') }}</label>
                <input id="signup-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required @error('password') aria-invalid="true" aria-describedby="signup-password-error" @enderror>
                <p class="site-muted site-small">{{ __('site.signup.password_hint') }}</p>
                @error('password')<p class="site-field-error" id="signup-password-error">{{ $message }}</p>@enderror
            </div>
            <div class="site-field">
                <label for="signup-password_confirmation">{{ __('site.signup.password_confirmation') }}</label>
                <input id="signup-password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" maxlength="128" required>
            </div>
            <div class="site-field">
                <label for="signup-timezone">{{ __('site.signup.timezone') }}</label>
                <select id="signup-timezone" name="timezone" required>
                    @foreach ($timezones as $zone)
                        <option value="{{ $zone }}" @selected($zone === $timezone)>{{ $zone }}</option>
                    @endforeach
                </select>
            </div>
            <div class="site-trap" aria-hidden="true">
                <label for="signup-website">{{ __('site.contact.website') }}</label>
                <input id="signup-website" name="website" type="text" tabindex="-1" autocomplete="off">
            </div>
            @if ($documents !== [])
                @foreach ($documents as $document)
                    <input type="hidden" name="policy_version_ids[]" value="{{ $document['version_id'] }}">
                @endforeach
                <div class="site-field site-check" data-documents>
                    <label>
                        <input type="checkbox" name="accept" value="1" required @error('accept') aria-invalid="true" @enderror>
                        <span>
                            {{ __('site.signup.accept') }}
                            @foreach ($documents as $document)
                                <a href="{{ $document['url'] }}" target="_blank" rel="noopener" data-document="{{ $document['type'] }}">{{ $document['title'] }}</a>@if (! $loop->last), @endif
                            @endforeach
                        </span>
                    </label>
                    @error('accept')<p class="site-field-error">{{ $message }}</p>@enderror
                </div>
            @endif
            <p><button type="submit" class="site-button">{{ __('site.signup.submit') }}</button></p>
            <p class="site-muted site-small">{{ __('site.signup.have_account') }} <a href="{{ $login_target }}">{{ __('site.nav.sign_in') }}</a></p>
        </form>
    </section>
@endsection
