<?php

namespace BusinessWatchdog\WooCommerce\Security;

final class SecretBox
{
    private const CIPHER = 'aes-256-gcm';

    public static function available(): bool
    {
        return function_exists('openssl_encrypt') && in_array(self::CIPHER, openssl_get_cipher_methods(), true);
    }

    public static function seal(string $plaintext): array
    {
        if (! self::available()) {
            return ['v' => 0, 'data' => base64_encode($plaintext)];
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return [
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode((string) $ciphertext),
        ];
    }

    public static function open(array $sealed): ?string
    {
        if (($sealed['v'] ?? null) === 0) {
            $plain = base64_decode((string) ($sealed['data'] ?? ''), true);

            return $plain === false ? null : $plain;
        }

        if (($sealed['v'] ?? null) !== 1 || ! self::available()) {
            return null;
        }

        $plain = openssl_decrypt(
            (string) base64_decode((string) ($sealed['data'] ?? ''), true),
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            (string) base64_decode((string) ($sealed['iv'] ?? ''), true),
            (string) base64_decode((string) ($sealed['tag'] ?? ''), true)
        );

        return $plain === false ? null : $plain;
    }

    private static function key(): string
    {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '') . '|' . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '') . '|business-watchdog-secret-box';

        return hash('sha256', $material, true);
    }
}
