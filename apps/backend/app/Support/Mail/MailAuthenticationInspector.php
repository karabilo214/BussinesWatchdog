<?php

namespace App\Support\Mail;

use App\Support\Network\DnsClient;

class MailAuthenticationInspector
{
    public function __construct(
        private readonly DnsClient $dns,
        private readonly DkimMailSigner $dkim,
    ) {}

    /**
     * @return array{ok: bool, checks: array<string, array{ok: bool, message: string}>}
     */
    public function inspect(): array
    {
        $fromDomain = $this->domainFromAddress((string) config('mail.from.address', ''));
        $returnPathDomain = $this->domainFromAddress((string) config('mail.return_path.address', '')) ?: $fromDomain;
        $dkimDomain = $this->dkim->domain();
        $selector = $this->dkim->selector();
        $providerManaged = (bool) config('mail_auth.dkim.provider_managed');
        $dnsKey = $dkimDomain !== '' && $selector !== '' ? $this->dkimDnsKey($selector, $dkimDomain) : null;

        $checks = [
            'from_domain' => $this->check($fromDomain !== '', 'MAIL_FROM_ADDRESS must contain a domain.'),
            'spf' => $this->check(
                $returnPathDomain !== '' && $this->hasSpf($returnPathDomain),
                "SPF TXT record is missing for {$returnPathDomain}.",
            ),
            'spf_alignment' => $this->check(
                $fromDomain !== '' && $this->aligned($returnPathDomain, $fromDomain),
                'MAIL_RETURN_PATH domain must equal, contain or be contained in the MAIL_FROM_ADDRESS domain for DMARC alignment.',
            ),
            'dkim_config' => $this->check(
                $providerManaged
                    ? $dkimDomain !== '' && $selector !== ''
                    : $this->dkim->enabled() && $this->dkim->configured() && $this->dkim->publicKey() !== null,
                $providerManaged
                    ? 'MAIL_DKIM_DOMAIN and MAIL_DKIM_SELECTOR are required for provider-managed DKIM.'
                    : 'DKIM signing is not enabled or the RSA private key cannot be read.',
            ),
            'dkim_dns' => $this->check(
                $dnsKey !== null,
                "DKIM public TXT record is missing or revoked at {$selector}._domainkey.{$dkimDomain}.",
            ),
            'dkim_key_match' => $this->check(
                $providerManaged || ($dnsKey !== null && $dnsKey === $this->dkim->publicKey()),
                'DKIM public TXT record does not match MAIL_DKIM_PRIVATE_KEY.',
            ),
            'dkim_alignment' => $this->check(
                $fromDomain !== '' && $this->aligned($dkimDomain, $fromDomain),
                'MAIL_DKIM_DOMAIN must equal, contain or be contained in the MAIL_FROM_ADDRESS domain for DMARC alignment.',
            ),
            'dmarc' => $this->check($fromDomain !== '' && $this->hasDmarc($fromDomain), "DMARC TXT record is missing for {$fromDomain}."),
        ];

        return [
            'ok' => collect($checks)->every(fn (array $check): bool => $check['ok']),
            'checks' => $checks,
        ];
    }

    private function hasSpf(string $domain): bool
    {
        return collect($this->dns->txtRecords($domain))
            ->contains(fn (string $record): bool => preg_match('/^v=spf1(\s|$)/i', trim($record)) === 1);
    }

    private function dkimDnsKey(string $selector, string $domain): ?string
    {
        foreach ($this->dns->txtRecords($selector.'._domainkey.'.$domain) as $record) {
            $tags = $this->tags($record);
            if (isset($tags['v']) && strcasecmp($tags['v'], 'DKIM1') !== 0) {
                continue;
            }

            $key = preg_replace('/\s+/', '', $tags['p'] ?? '');
            if ($key !== '') {
                return $key;
            }
        }

        return null;
    }

    private function hasDmarc(string $domain): bool
    {
        $labels = explode('.', $domain);

        while (count($labels) >= 2) {
            foreach ($this->dns->txtRecords('_dmarc.'.implode('.', $labels)) as $record) {
                $tags = $this->tags($record);
                if (strcasecmp($tags['v'] ?? '', 'DMARC1') === 0 && in_array(strtolower($tags['p'] ?? ''), ['none', 'quarantine', 'reject'], true)) {
                    return true;
                }
            }

            array_shift($labels);
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function tags(string $record): array
    {
        $tags = [];

        foreach (explode(';', $record) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $tags[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $tags;
    }

    private function aligned(string $domain, string $fromDomain): bool
    {
        return $domain !== '' && $fromDomain !== ''
            && ($domain === $fromDomain || str_ends_with($domain, '.'.$fromDomain) || str_ends_with($fromDomain, '.'.$domain));
    }

    private function domainFromAddress(string $address): string
    {
        $address = trim($address);
        $at = strrpos($address, '@');

        return $at === false ? '' : strtolower(rtrim(substr($address, $at + 1), '>'));
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function check(bool $ok, string $message): array
    {
        return ['ok' => $ok, 'message' => $ok ? 'ok' : $message];
    }
}
