<?php

namespace Tests\Feature\Mail;

use App\Support\Mail\DkimMailSigner;
use App\Support\Mail\MailAuthenticationInspector;
use App\Support\Network\DnsClient;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MailAuthenticationTest extends TestCase
{
    public function test_dkim_signer_adds_signature_header_when_configured(): void
    {
        [$privateKey] = $this->keyPair();

        config([
            'mail_auth.dkim.enabled' => true,
            'mail_auth.dkim.domain' => 'example.com',
            'mail_auth.dkim.selector' => 'bw1',
            'mail_auth.dkim.private_key' => $privateKey,
        ]);

        $message = (new Email)
            ->from('watchdog@example.com')
            ->to('owner@example.net')
            ->subject('Test')
            ->text('Signed message');

        app(DkimMailSigner::class)->sign($message);

        $this->assertTrue($message->getHeaders()->has('DKIM-Signature'));
        $this->assertTrue($message->getHeaders()->has('Date'));
        $this->assertTrue($message->getHeaders()->has('Message-ID'));
        $this->assertStringContainsString('d=example.com', $message->getHeaders()->get('DKIM-Signature')->getBodyAsString());
        $this->assertStringContainsString('s=bw1', $message->getHeaders()->get('DKIM-Signature')->getBodyAsString());
    }

    public function test_sent_multipart_message_carries_a_valid_dkim_signature(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();

        config([
            'mail.default' => 'array',
            'mail.from.address' => 'watchdog@example.com',
            'mail.return_path.address' => 'bounce@example.com',
            'mail_auth.dkim.enabled' => true,
            'mail_auth.dkim.domain' => 'example.com',
            'mail_auth.dkim.selector' => 'bw1',
            'mail_auth.dkim.private_key' => str_replace("\n", '\\n', $privateKey),
        ]);
        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
        Mail::clearResolvedInstances();

        Mail::html('<p>Инцидент: оплата не прошла</p><p>'.str_repeat('Длинная строка письма ', 20).'</p>', function (Message $message): void {
            $message->to('owner@example.net')->subject('Инцидент в магазине');
            $message->getSymfonyMessage()->text("Инцидент: оплата не прошла  \n\n");
        });

        $raw = app('mailer')->getSymfonyTransport()->messages()->last()->toString();

        $this->assertStringContainsString('multipart/alternative', $raw);
        $this->assertStringContainsString('Return-Path: <bounce@example.com>', $raw);
        $this->assertTrue($this->verifyDkim($raw, $publicKey));
        $this->assertFalse($this->verifyDkim($raw."\r\nextra line", $publicKey));
        $this->assertFalse($this->verifyDkim(str_replace('owner@example.net', 'other@example.net', $raw), $publicKey));
    }

    public function test_unreadable_dkim_key_does_not_block_sending(): void
    {
        Log::spy();

        config([
            'mail.default' => 'array',
            'mail.from.address' => 'watchdog@example.com',
            'mail_auth.dkim.enabled' => true,
            'mail_auth.dkim.domain' => 'example.com',
            'mail_auth.dkim.selector' => 'bw1',
            'mail_auth.dkim.private_key' => null,
            'mail_auth.dkim.private_key_path' => '/nonexistent/dkim.pem',
        ]);
        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
        Mail::clearResolvedInstances();

        Mail::raw('Alert body', fn (Message $message) => $message->to('owner@example.net')->subject('Alert'));

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertStringNotContainsString('DKIM-Signature', $sent->last()->toString());
        Log::shouldHaveReceived('error')->with('mail.dkim_signing_failed', \Mockery::type('array'))->once();
    }

    public function test_mail_authentication_inspector_requires_spf_dkim_and_dmarc(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $dns = $this->dnsFor('example.com', 'bw1', $publicKey);
        $this->app->instance(DnsClient::class, $dns);
        $this->configureSelfSigned($privateKey);

        $result = app(MailAuthenticationInspector::class)->inspect();

        $this->assertTrue($result['ok'], json_encode($result['checks']));
    }

    public function test_mail_authentication_inspector_rejects_mismatched_or_revoked_dkim_key(): void
    {
        [$privateKey] = $this->keyPair();
        [, $otherPublicKey] = $this->keyPair();
        $dns = $this->dnsFor('example.com', 'bw1', $otherPublicKey);
        $this->app->instance(DnsClient::class, $dns);
        $this->configureSelfSigned($privateKey);

        $result = app(MailAuthenticationInspector::class)->inspect();
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['checks']['dkim_dns']['ok']);
        $this->assertFalse($result['checks']['dkim_key_match']['ok']);

        $dns->txt['bw1._domainkey.example.com'] = ['v=DKIM1; k=rsa; p='];
        $result = app(MailAuthenticationInspector::class)->inspect();
        $this->assertFalse($result['checks']['dkim_dns']['ok']);
    }

    public function test_mail_authentication_inspector_rejects_unreadable_private_key(): void
    {
        [, $publicKey] = $this->keyPair();
        $this->app->instance(DnsClient::class, $this->dnsFor('example.com', 'bw1', $publicKey));
        $this->configureSelfSigned('-----BEGIN PRIVATE KEY-----\nnot-a-key\n-----END PRIVATE KEY-----');

        $result = app(MailAuthenticationInspector::class)->inspect();

        $this->assertFalse($result['checks']['dkim_config']['ok']);
        $this->assertFalse($result['ok']);
    }

    public function test_mail_authentication_inspector_accepts_provider_managed_dkim_with_subdomain_bounces(): void
    {
        $dns = new FakeMailDnsClient;
        $dns->txt['pm-bounces.example.com'] = ['v=spf1 include:spf.mtasv.net ~all'];
        $dns->txt['provider1._domainkey.example.com'] = ['k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC'];
        $dns->txt['_dmarc.example.com'] = ['v=DMARC1; p=reject'];
        $this->app->instance(DnsClient::class, $dns);

        config([
            'mail.from.address' => 'watchdog@alerts.example.com',
            'mail.return_path.address' => 'bounce@pm-bounces.example.com',
            'mail_auth.dkim.enabled' => false,
            'mail_auth.dkim.provider_managed' => true,
            'mail_auth.dkim.domain' => 'example.com',
            'mail_auth.dkim.selector' => 'provider1',
            'mail_auth.dkim.private_key' => null,
        ]);

        $result = app(MailAuthenticationInspector::class)->inspect();
        $this->assertFalse($result['checks']['spf_alignment']['ok']);
        $this->assertTrue($result['checks']['dkim_alignment']['ok']);

        config(['mail.from.address' => 'watchdog@example.com']);
        $result = app(MailAuthenticationInspector::class)->inspect();
        $this->assertTrue($result['ok'], json_encode($result['checks']));
    }

    public function test_mail_authentication_inspector_finds_dmarc_on_parent_domain(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $dns = $this->dnsFor('example.com', 'bw1', $publicKey);
        $dns->txt['alerts.example.com'] = ['v=spf1 include:_spf.mailer.example -all'];
        $this->app->instance(DnsClient::class, $dns);
        $this->configureSelfSigned($privateKey);
        config([
            'mail.from.address' => 'watchdog@alerts.example.com',
            'mail.return_path.address' => null,
        ]);

        $result = app(MailAuthenticationInspector::class)->inspect();

        $this->assertTrue($result['ok'], json_encode($result['checks']));
    }

    public function test_mail_auth_check_fails_in_strict_mode_when_dns_is_missing(): void
    {
        [$privateKey] = $this->keyPair();
        $this->app->instance(DnsClient::class, new FakeMailDnsClient);
        $this->configureSelfSigned($privateKey);

        $this->artisan('mail:auth-check --strict')
            ->expectsOutputToContain('spf=missing')
            ->expectsOutputToContain('dkim_dns=missing')
            ->expectsOutputToContain('dmarc=missing')
            ->assertFailed();
    }

    public function test_mail_auth_check_reports_but_passes_without_strict_outside_production(): void
    {
        $this->app->instance(DnsClient::class, new FakeMailDnsClient);

        $this->artisan('mail:auth-check')
            ->expectsOutputToContain('Production email authentication is incomplete.')
            ->assertSuccessful();
    }

    private function configureSelfSigned(string $privateKey): void
    {
        config([
            'mail.from.address' => 'watchdog@example.com',
            'mail.return_path.address' => 'bounce@example.com',
            'mail_auth.dkim.enabled' => true,
            'mail_auth.dkim.provider_managed' => false,
            'mail_auth.dkim.domain' => 'example.com',
            'mail_auth.dkim.selector' => 'bw1',
            'mail_auth.dkim.private_key' => $privateKey,
        ]);
    }

    private function dnsFor(string $domain, string $selector, string $publicKey): FakeMailDnsClient
    {
        $dns = new FakeMailDnsClient;
        $dns->txt[$domain] = ['google-site-verification=abc', 'v=spf1 include:_spf.mailer.example -all'];
        $dns->txt[$selector.'._domainkey.'.$domain] = ['v=DKIM1; k=rsa; p='.$publicKey];
        $dns->txt['_dmarc.'.$domain] = ['v=DMARC1; p=quarantine; rua=mailto:dmarc@'.$domain];

        return $dns;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function keyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $public = preg_replace('/-----[^-]+-----|\s+/', '', openssl_pkey_get_details($key)['key']);

        return [$privateKey, $public];
    }

    private function verifyDkim(string $raw, string $publicKey): bool
    {
        [$headerBlock, $body] = explode("\r\n\r\n", $raw, 2);
        $headers = [];
        foreach (preg_split('/\r\n(?![ \t])/', $headerBlock) as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[] = [$name, $value];
        }

        $signature = collect($headers)->first(fn (array $header): bool => strcasecmp($header[0], 'DKIM-Signature') === 0);
        $tags = [];
        foreach (explode(';', preg_replace('/\s+/', '', $signature[1])) as $pair) {
            if (str_contains($pair, '=')) {
                [$tag, $value] = explode('=', $pair, 2);
                $tags[$tag] = $value;
            }
        }
        $this->assertSame('relaxed/relaxed', $tags['c']);
        $this->assertStringNotContainsStringIgnoringCase('return-path', $tags['h']);

        $canonicalBody = implode("\r\n", array_map(
            fn (string $line): string => rtrim(preg_replace('/[ \t]+/', ' ', $line), " \t"),
            explode("\r\n", $body),
        ));
        $canonicalBody = rtrim($canonicalBody, "\r\n");
        $canonicalBody = $canonicalBody === '' ? '' : $canonicalBody."\r\n";
        if (base64_encode(hash('sha256', $canonicalBody, true)) !== $tags['bh']) {
            return false;
        }

        $canonical = fn (string $name, string $value): string => strtolower(trim($name)).':'.trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\r\n/', '', $value)));
        $remaining = $headers;
        $data = '';
        foreach (explode(':', $tags['h']) as $name) {
            for ($i = count($remaining) - 1; $i >= 0; $i--) {
                if (strcasecmp(trim($remaining[$i][0]), $name) === 0) {
                    $data .= $canonical($remaining[$i][0], $remaining[$i][1])."\r\n";
                    array_splice($remaining, $i, 1);
                    break;
                }
            }
        }
        $data .= $canonical($signature[0], preg_replace('/(\bb=)[^;]*$/', '$1', $signature[1]));

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($publicKey, 64, "\n")."-----END PUBLIC KEY-----\n";

        return openssl_verify($data, base64_decode($tags['b']), $pem, OPENSSL_ALGO_SHA256) === 1;
    }
}

class FakeMailDnsClient implements DnsClient
{
    /**
     * @var array<string, list<string>>
     */
    public array $txt = [];

    public function resolveIps(string $host): array
    {
        return [];
    }

    public function txtRecords(string $name): array
    {
        return $this->txt[$name] ?? [];
    }
}
