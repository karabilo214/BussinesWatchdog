<?php

use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Membership;
use App\Models\PairingCode;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Integrations\IntegrationCredentialService;
use App\Support\Stores\StoreVerificationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$action = getenv('BW_E2E_ACTION');
$out = [];

if ($action === 'setup') {
    $baseUrl = getenv('BW_E2E_BASE_URL');
    $tenant = Tenant::query()->create(['name' => 'E2E '.$baseUrl, 'timezone' => 'Europe/Kyiv']);
    $user = User::query()->create(['name' => 'E2E', 'email' => 'e2e-'.Str::random(10).'@example.test', 'password_hash' => Hash::make(Str::random(32)), 'locale' => 'en']);
    Membership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);
    $store = Store::query()->create(['tenant_id' => $tenant->id, 'name' => 'E2E', 'base_url' => $baseUrl, 'timezone' => 'Europe/Kyiv', 'default_currency' => 'EUR']);
    $code = 'bwpc_'.Str::random(32);
    PairingCode::query()->create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'code_hash' => hash('sha256', $code), 'created_by' => $user->id, 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
    $out = ['store_id' => $store->id, 'pairing_code' => $code];
} elseif ($action === 'start-verification') {
    $store = Store::query()->findOrFail(getenv('BW_E2E_STORE_ID'));
    $verification = app(StoreVerificationService::class)->start($store, StoreVerification::METHOD_PLUGIN_CHALLENGE);
    $out = ['verification_id' => $verification->id, 'challenge' => app(StoreVerificationService::class)->challenge($verification->id)];
} elseif ($action === 'request-rotation') {
    $integration = Integration::query()->where('store_id', getenv('BW_E2E_STORE_ID'))->firstOrFail();
    app(IntegrationCredentialService::class)->requestRotation($integration, null);
    $out = ['integration_id' => $integration->id];
} elseif ($action === 'status') {
    $integration = Integration::query()->where('store_id', getenv('BW_E2E_STORE_ID'))->first();
    $out = [
        'integration_provider' => $integration?->provider,
        'last_heartbeat_at' => $integration?->last_heartbeat_at?->toJSON(),
        'credentials' => $integration ? IntegrationCredential::query()->where('integration_id', $integration->id)->orderBy('created_at')->pluck('status')->all() : [],
    ];
}

echo 'BW_E2E_JSON='.json_encode($out).PHP_EOL;
