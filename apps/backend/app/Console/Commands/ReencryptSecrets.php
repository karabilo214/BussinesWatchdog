<?php

namespace App\Console\Commands;

use App\Models\IntegrationCredential;
use App\Models\NotificationChannel;
use App\Support\Security\Keyring;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReencryptSecrets extends Command
{
    protected $signature = 'security:reencrypt-secrets {--chunk=200}';

    protected $description = 'Re-encrypt integration credential secrets and notification destinations with the current keyring version.';

    public function handle(Keyring $keyring): int
    {
        $current = $keyring->currentVersion();
        $chunk = max(1, (int) $this->option('chunk'));
        $credentials = 0;
        $channels = 0;

        IntegrationCredential::query()
            ->where('key_version', '!=', $current)
            ->where('status', '!=', IntegrationCredential::STATUS_REVOKED)
            ->chunkById($chunk, function ($items) use ($keyring, &$credentials): void {
                foreach ($items as $credential) {
                    DB::transaction(function () use ($keyring, $credential, &$credentials): void {
                        $encrypted = $keyring->encrypt($keyring->decrypt($credential->ciphertext, $credential->key_version));
                        $credential->forceFill([
                            'ciphertext' => $encrypted['ciphertext'],
                            'key_version' => $encrypted['key_version'],
                        ])->save();
                        $credentials++;
                    });
                }
            });

        NotificationChannel::query()
            ->where('key_version', '!=', $current)
            ->chunkById($chunk, function ($items) use ($keyring, &$channels): void {
                foreach ($items as $channel) {
                    DB::transaction(function () use ($keyring, $channel, &$channels): void {
                        $encrypted = $keyring->encrypt($keyring->decrypt($channel->destination_ciphertext, $channel->key_version));
                        $channel->forceFill([
                            'destination_ciphertext' => $encrypted['ciphertext'],
                            'key_version' => $encrypted['key_version'],
                        ])->save();
                        $channels++;
                    });
                }
            });

        $this->components->info(sprintf(
            'Re-encrypted to keyring v%d: credentials=%d notification_channels=%d',
            $current,
            $credentials,
            $channels,
        ));

        return self::SUCCESS;
    }
}
