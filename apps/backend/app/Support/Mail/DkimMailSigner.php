<?php

namespace App\Support\Mail;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email;
use Throwable;

class DkimMailSigner
{
    public function enabled(): bool
    {
        return (bool) config('mail_auth.dkim.enabled');
    }

    public function configured(): bool
    {
        return $this->keyMaterial() !== null
            && $this->domain() !== ''
            && $this->selector() !== '';
    }

    public function sign(Email $message): void
    {
        if (! $this->enabled() || ! $this->configured()) {
            return;
        }

        try {
            $signer = new DkimSigner(
                $this->keyMaterial(),
                $this->domain(),
                $this->selector(),
                passphrase: $this->passphrase(),
            );

            $signed = $signer->sign($message);
        } catch (Throwable $exception) {
            Log::error('mail.dkim_signing_failed', [
                'domain' => $this->domain(),
                'selector' => $this->selector(),
                'error' => $exception::class,
            ]);

            return;
        }

        $headers = $message->getHeaders();

        foreach ($signed->getHeaders()->getNames() as $name) {
            $headers->remove($name);
        }

        foreach ($signed->getHeaders()->all() as $header) {
            $headers->add($header);
        }
    }

    public function publicKey(): ?string
    {
        $material = $this->keyMaterial();
        if ($material === null) {
            return null;
        }

        try {
            $key = openssl_pkey_get_private($material, $this->passphrase());
        } catch (Throwable) {
            return null;
        }

        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);
        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            return null;
        }

        return preg_replace('/-----[^-]+-----|\s+/', '', (string) $details['key']);
    }

    public function domain(): string
    {
        return strtolower(trim((string) config('mail_auth.dkim.domain', '')));
    }

    public function selector(): string
    {
        return trim((string) config('mail_auth.dkim.selector', ''));
    }

    private function passphrase(): string
    {
        return (string) config('mail_auth.dkim.passphrase', '');
    }

    private function keyMaterial(): ?string
    {
        $key = trim((string) config('mail_auth.dkim.private_key', ''));
        if ($key !== '') {
            return str_replace('\\n', "\n", $key);
        }

        $path = trim((string) config('mail_auth.dkim.private_key_path', ''));
        if ($path === '') {
            return null;
        }

        return str_starts_with($path, 'file://') ? $path : 'file://'.$path;
    }
}
