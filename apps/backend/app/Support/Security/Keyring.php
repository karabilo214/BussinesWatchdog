<?php

namespace App\Support\Security;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

class Keyring
{
    /**
     * @var array<int, Encrypter>
     */
    private array $encrypters = [];

    public function currentVersion(): int
    {
        return (int) config('watchdog.keyring.current', 1);
    }

    /**
     * @return array{ciphertext: string, key_version: int}
     */
    public function encrypt(string $plaintext): array
    {
        $version = $this->currentVersion();

        return [
            'ciphertext' => $this->encrypter($version)->encryptString($plaintext),
            'key_version' => $version,
        ];
    }

    public function decrypt(string $ciphertext, int $keyVersion): string
    {
        return $this->encrypter($keyVersion)->decryptString($ciphertext);
    }

    public function isReady(): bool
    {
        try {
            $probe = $this->encrypt('keyring-probe');

            return $this->decrypt($probe['ciphertext'], $probe['key_version']) === 'keyring-probe';
        } catch (DecryptException) {
            return false;
        }
    }

    private function encrypter(int $version): Encrypter
    {
        if (isset($this->encrypters[$version])) {
            return $this->encrypters[$version];
        }

        $entry = $this->keys()[$version] ?? null;

        if ($entry === null || $entry['key'] === '') {
            throw new DecryptException('keyring_version_unavailable');
        }

        $key = Str::startsWith($entry['key'], 'base64:')
            ? base64_decode(Str::after($entry['key'], 'base64:'), true)
            : $entry['key'];

        if (! is_string($key) || ! Encrypter::supported($key, $entry['cipher'])) {
            throw new DecryptException('keyring_version_invalid');
        }

        return $this->encrypters[$version] = new Encrypter($key, $entry['cipher']);
    }

    /**
     * @return array<int, array{key: string, cipher: string}>
     */
    private function keys(): array
    {
        $keys = [
            1 => [
                'key' => (string) (config('watchdog.keyring.v1_key') ?: config('app.key')),
                'cipher' => (string) config('app.cipher', 'aes-256-cbc'),
            ],
        ];

        foreach (array_filter(explode(',', (string) config('watchdog.keyring.additional_keys', ''))) as $item) {
            [$version, $key] = array_pad(explode(':', trim($item), 2), 2, '');

            if (ctype_digit($version) && (int) $version > 1) {
                $keys[(int) $version] = ['key' => $key, 'cipher' => 'aes-256-gcm'];
            }
        }

        return $keys;
    }
}
