@extends('site.layout')

@section('content')
    <section class="site-wrap site-section site-narrow">
        <h1 id="form-title">{{ $title }}</h1>
        <p class="site-lead">{{ __('site.signup.lead') }}</p>
        @if ($connect_site)
            <p class="site-note" data-plugin-connect>{{ __('site.signup.plugin_connect', ['site' => $connect_site]) }}</p>
        @endif

        @include('site.partials.form-errors', ['errorFields' => ['salutation' => 'signup-salutation', 'first_name' => 'signup-first_name', 'last_name' => 'signup-last_name', 'company_name' => 'signup-company_name', 'organization_name' => 'signup-organization_name', 'email' => 'signup-email', 'password' => 'signup-password', 'password_confirmation' => 'signup-password_confirmation', 'timezone' => 'signup-timezone', 'accept' => 'signup-accept']])

        <form method="post" action="{{ \App\Support\Site\SiteContent::url($locale, 'signup') }}" class="site-form site-card" data-form="signup" aria-labelledby="form-title" data-submitting="{{ __('site.forms.submitting') }}">
            @csrf
            <p class="site-muted site-small">{{ __('site.forms.required_hint') }}</p>
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
            @foreach ([['first_name', 'text', 'given-name', 100, false], ['last_name', 'text', 'family-name', 100, true], ['company_name', 'text', 'organization', 150, false], ['organization_name', 'text', 'off', 100, true], ['email', 'email', 'username', 255, true]] as [$field, $type, $autocomplete, $max, $required])
                <div class="site-field">
                    <label for="signup-{{ $field }}">{{ __('site.signup.'.($field === 'organization_name' ? 'organization' : $field)) }}@unless ($required) <span class="site-muted">({{ __('site.signup.optional') }})</span>@endunless</label>
                    <input id="signup-{{ $field }}" name="{{ $field }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}" @if ($field === 'email') autocapitalize="none" spellcheck="false" @endif maxlength="{{ $max }}" @if ($required) required @endif value="{{ old($field) }}" @error($field) aria-invalid="true" aria-describedby="signup-{{ $field }}-error" @enderror>
                    @error($field)<p class="site-field-error" id="signup-{{ $field }}-error">{{ $message }}</p>@enderror
                </div>
            @endforeach
            <div class="site-field">
                <label for="signup-password">{{ __('site.signup.password') }}</label>
                <input id="signup-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required @error('password') aria-invalid="true" @enderror aria-describedby="signup-password-hint @error('password') signup-password-error @enderror">
                <button type="button" class="site-text-button" hidden data-toggle-password="signup-password" aria-controls="signup-password" aria-pressed="false" data-show="{{ __('site.forms.show_password') }}" data-hide="{{ __('site.forms.hide_password') }}">{{ __('site.forms.show_password') }}</button>
                <p id="signup-password-hint" class="site-muted site-small">{{ __('site.signup.password_hint') }}</p>
                @error('password')<p class="site-field-error" id="signup-password-error">{{ $message }}</p>@enderror
            </div>
            <div class="site-field">
                <label for="signup-password_confirmation">{{ __('site.signup.password_confirmation') }}</label>
                <input id="signup-password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required data-mismatch="{{ __('site.forms.password_mismatch') }}" @error('password_confirmation') aria-invalid="true" aria-describedby="signup-password_confirmation-error" @enderror>
                <button type="button" class="site-text-button" hidden data-toggle-password="signup-password_confirmation" aria-controls="signup-password_confirmation" aria-pressed="false" data-show="{{ __('site.forms.show_password') }}" data-hide="{{ __('site.forms.hide_password') }}">{{ __('site.forms.show_password') }}</button>
                @error('password_confirmation')<p class="site-field-error" id="signup-password_confirmation-error">{{ $message }}</p>@enderror
            </div>
            <div class="site-field">
                <label for="signup-timezone">{{ __('site.signup.timezone') }}</label>
                <select id="signup-timezone" name="timezone" required @error('timezone') aria-invalid="true" aria-describedby="signup-timezone-error" @enderror>
                    @foreach ($timezones as $zone)
                        <option value="{{ $zone }}" @selected($zone === $timezone)>{{ $zone }}</option>
                    @endforeach
                </select>
                @error('timezone')<p class="site-field-error" id="signup-timezone-error">{{ $message }}</p>@enderror
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
                        <input id="signup-accept" type="checkbox" name="accept" value="1" required @error('accept') aria-invalid="true" aria-describedby="signup-accept-error" @enderror>
                        <span>
                            {{ __('site.signup.accept') }}
                            @foreach ($documents as $document)
                                <a href="{{ $document['url'] }}" target="_blank" rel="noopener" data-document="{{ $document['type'] }}">{{ $document['title'] }}</a>@if (! $loop->last), @endif
                            @endforeach
                        </span>
                    </label>
                    @error('accept')<p id="signup-accept-error" class="site-field-error">{{ $message }}</p>@enderror
                </div>
            @endif
            <p><button type="submit" class="site-button">{{ __('site.signup.submit') }}</button></p>
            <p class="site-muted site-small">{{ __('site.signup.have_account') }} <a href="{{ $login_target }}">{{ __('site.nav.sign_in') }}</a></p>
        </form>
    </section>
@endsection

@push('scripts')
    @if ($formsScript = \App\Support\Site\SiteAssets::formsScript())
        <script type="module" nonce="{{ request()->attributes->get('csp_nonce') }}" src="{{ $formsScript }}"></script>
    @endif
@endpush
