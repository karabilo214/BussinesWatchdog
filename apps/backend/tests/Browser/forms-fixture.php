<?php

use App\Models\PlatformStaff;
use App\Models\PublicPage;
use App\Models\PublicPageVersion;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\Totp;
use App\Support\Security\Keyring;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Synthetic accounts for the local browser check; refuse every other database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local', 'testing') || config('database.default') !== 'pgsql' || DB::connection()->getDatabaseName() !== 'bw_forms_a11y') {
    throw new RuntimeException('Use only the isolated bw_forms_a11y database.');
}

if (($argv[1] ?? '') === 'code') {
    $user = User::where('email', 'forms-staff@example.test')->firstOrFail();
    $user->forceFill(['mfa_last_used_step' => null])->save();
    echo app(Totp::class)->codeAt(app(Keyring::class)->decrypt($user->mfa_secret_ciphertext, $user->mfa_key_version), app(Totp::class)->step(time()));
    exit;
}
foreach (['service_terms', 'privacy_notice'] as $type) {
    $page = PublicPage::firstOrCreate(['policy_type' => $type], ['kind' => 'page', 'slug' => str_replace('_', '-', $type), 'path' => str_replace('_', '-', $type), 'menu' => 'footer', 'sort' => 0]);
    foreach (['en', 'de', 'ru'] as $locale) {
        PublicPageVersion::firstOrCreate(['page_id' => $page->id, 'locale' => $locale, 'version' => 1], [
            'title' => $type, 'body' => '<p>Synthetic local policy.</p>', 'content_hash' => hash('sha256', $type), 'state' => 'published', 'published_at' => now(),
        ]);
    }
}
$user = User::firstOrCreate(['email' => 'forms-staff@example.test'], ['name' => 'Synthetic Staff', 'password_hash' => Hash::make('Local-forms-test-12345'), 'locale' => 'en', 'email_verified_at' => now()]);
$mfa = app(MfaService::class);
if (! $mfa->enabled($user)) {
    $setup = $mfa->beginSetup($user);
    $mfa->confirm($user, app(Totp::class)->codeAt($setup['secret'], app(Totp::class)->step(time())));
}
PlatformStaff::firstOrCreate(['user_id' => $user->id], ['role' => PlatformStaff::ROLE_OWNER, 'state' => PlatformStaff::STATE_ACTIVE, 'mfa_required' => true]);
echo "Synthetic forms fixture ready.\n";
