@extends('site.layout')

@section('content')
    <section class="site-wrap site-section site-narrow site-form-wide">
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
            <div class="site-row site-row-name">
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
                <div class="site-field">
                    <label for="signup-first_name">{{ __('site.signup.first_name') }} <span class="site-muted">({{ __('site.signup.optional') }})</span></label>
                    <input id="signup-first_name" name="first_name" type="text" autocomplete="given-name" maxlength="100" value="{{ old('first_name') }}" @error('first_name') aria-invalid="true" aria-describedby="signup-first_name-error" @enderror>
                    @error('first_name')<p class="site-field-error" id="signup-first_name-error">{{ $message }}</p>@enderror
                </div>
                <div class="site-field">
                    <label for="signup-last_name">{{ __('site.signup.last_name') }}</label>
                    <input id="signup-last_name" name="last_name" type="text" autocomplete="family-name" maxlength="100" required value="{{ old('last_name') }}" @error('last_name') aria-invalid="true" aria-describedby="signup-last_name-error" @enderror>
                    @error('last_name')<p class="site-field-error" id="signup-last_name-error">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="site-row site-row-2">
                <div class="site-field">
                    <label for="signup-company_name">{{ __('site.signup.company_name') }} <span class="site-muted">({{ __('site.signup.optional') }})</span></label>
                    <input id="signup-company_name" name="company_name" type="text" autocomplete="organization" maxlength="150" value="{{ old('company_name') }}" @error('company_name') aria-invalid="true" aria-describedby="signup-company_name-error" @enderror>
                    @error('company_name')<p class="site-field-error" id="signup-company_name-error">{{ $message }}</p>@enderror
                </div>
                <div class="site-field">
                    <label for="signup-organization_name">{{ __('site.signup.organization') }}</label>
                    <input id="signup-organization_name" name="organization_name" type="text" autocomplete="off" maxlength="100" required value="{{ old('organization_name') }}" @error('organization_name') aria-invalid="true" aria-describedby="signup-organization_name-error" @enderror>
                    @error('organization_name')<p class="site-field-error" id="signup-organization_name-error">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="site-field">
                <label for="signup-email">{{ __('site.signup.email') }}</label>
                <input id="signup-email" name="email" type="email" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="255" required value="{{ old('email') }}" @error('email') aria-invalid="true" aria-describedby="signup-email-error" @enderror>
                @error('email')<p class="site-field-error" id="signup-email-error">{{ $message }}</p>@enderror
            </div>
            <div class="site-row site-row-2">
                <div class="site-field">
                    <label for="signup-password">{{ __('site.signup.password') }}</label>
                    <div class="site-input-group">
                        <input id="signup-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required @error('password') aria-invalid="true" @enderror aria-describedby="signup-password-hint @error('password') signup-password-error @enderror">
                        <button type="button" class="site-icon-button" hidden data-toggle-password="signup-password" aria-controls="signup-password" aria-pressed="false" aria-label="{{ __('site.forms.show_password') }}" title="{{ __('site.forms.show_password') }}" data-show="{{ __('site.forms.show_password') }}" data-hide="{{ __('site.forms.hide_password') }}">@include('site.partials.icon', ['name' => 'eye', 'role' => 'show'])@include('site.partials.icon', ['name' => 'eye-off', 'role' => 'hide', 'hidden' => true])</button>
                    </div>
                    <p id="signup-password-hint" class="site-muted site-small">{{ __('site.signup.password_hint') }}</p>
                    @error('password')<p class="site-field-error" id="signup-password-error">{{ $message }}</p>@enderror
                </div>
                <div class="site-field">
                    <label for="signup-password_confirmation">{{ __('site.signup.password_confirmation') }}</label>
                    <div class="site-input-group">
                        <input id="signup-password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required data-mismatch="{{ __('site.forms.password_mismatch') }}" @error('password_confirmation') aria-invalid="true" aria-describedby="signup-password_confirmation-error" @enderror>
                        <button type="button" class="site-icon-button" hidden data-toggle-password="signup-password_confirmation" aria-controls="signup-password_confirmation" aria-pressed="false" aria-label="{{ __('site.forms.show_password') }}" title="{{ __('site.forms.show_password') }}" data-show="{{ __('site.forms.show_password') }}" data-hide="{{ __('site.forms.hide_password') }}">@include('site.partials.icon', ['name' => 'eye', 'role' => 'show'])@include('site.partials.icon', ['name' => 'eye-off', 'role' => 'hide', 'hidden' => true])</button>
                    </div>
                    @error('password_confirmation')<p class="site-field-error" id="signup-password_confirmation-error">{{ $message }}</p>@enderror
                </div>
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
