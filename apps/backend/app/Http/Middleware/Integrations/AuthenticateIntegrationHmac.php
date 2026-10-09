<?php

namespace App\Http\Middleware\Integrations;

use App\Exceptions\Integrations\InvalidIntegrationCredentialSecret;
use App\Models\IntegrationCredential;
use App\Support\Integrations\IntegrationCredentialService;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIntegrationHmac
{
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    private const NONCE_TTL_SECONDS = 600;

    public function __construct(
        private readonly IntegrationCredentialService $credentials,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $headers = $this->signatureHeaders($request);

        if ($headers === null) {
            return $this->unauthorized('signature_invalid', 'Integration signature headers are required.');
        }

        if ($request->getQueryString() !== null) {
            return $this->unauthorized('signature_invalid', 'Signed integration requests must not include a query string.');
        }

        if (! ctype_digit($headers['timestamp'])) {
            return $this->unauthorized('timestamp_out_of_range', 'Integration signature timestamp is invalid.');
        }

        $timestamp = (int) $headers['timestamp'];

        if (abs(now()->getTimestamp() - $timestamp) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return $this->unauthorized('timestamp_out_of_range', 'Integration signature timestamp is outside the allowed window.');
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $headers['signature'])) {
            return $this->unauthorized('signature_invalid', 'Integration signature format is invalid.');
        }

        /** @var IntegrationCredential|null $credential */
        $credential = IntegrationCredential::query()
            ->with('integration')
            ->where('key_id', $headers['key_id'])
            ->where('kind', IntegrationCredential::KIND_PLUGIN_HMAC)
            ->first();

        if ($credential === null || $credential->integration === null) {
            return $this->unauthorized('signature_invalid', 'Integration credential was not found.');
        }

        if (! $this->isUsable($credential)) {
            return $this->unauthorized('credential_revoked', 'Integration credential is not active.');
        }

        try {
            $secret = $this->credentialSecret($credential);
        } catch (InvalidIntegrationCredentialSecret) {
            return $this->unauthorized('signature_invalid', 'Integration credential secret is invalid.');
        }

        $expectedSignature = hash_hmac('sha256', $this->canonicalString($request, $headers), $secret);

        if (! hash_equals($expectedSignature, $headers['signature'])) {
            return $this->unauthorized('signature_invalid', 'Integration signature is invalid.');
        }

        $nonceKey = sprintf('integration_nonce:%s:%s', $headers['key_id'], $headers['nonce']);

        if (! Cache::add($nonceKey, true, self::NONCE_TTL_SECONDS)) {
            return $this->unauthorized('nonce_replayed', 'Integration signature nonce was already used.');
        }

        $this->credentials->confirmActiveCredential($credential);

        $request->attributes->set('integration', $credential->integration);
        $request->attributes->set('integration_credential', $credential);

        return $next($request);
    }

    /**
     * @return array{key_id: string, timestamp: string, nonce: string, signature: string}|null
     */
    private function signatureHeaders(Request $request): ?array
    {
        $version = $request->header('X-BW-Signature-Version');
        $keyId = $request->header('X-BW-Key-Id');
        $timestamp = $request->header('X-BW-Timestamp');
        $nonce = $request->header('X-BW-Nonce');
        $signature = $request->header('X-BW-Signature');

        if ($version !== '1' || $keyId === null || $timestamp === null || $nonce === null || $signature === null) {
            return null;
        }

        if (! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $nonce)) {
            return null;
        }

        return [
            'key_id' => $keyId,
            'timestamp' => $timestamp,
            'nonce' => strtolower($nonce),
            'signature' => $signature,
        ];
    }

    /**
     * @param  array{key_id: string, timestamp: string, nonce: string, signature: string}  $headers
     */
    private function canonicalString(Request $request, array $headers): string
    {
        return implode("\n", [
            'v1',
            $headers['timestamp'],
            $headers['nonce'],
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            hash('sha256', $request->getContent()),
        ]);
    }

    private function credentialSecret(IntegrationCredential $credential): string
    {
        try {
            $secretBase64 = $this->credentials->secretFor($credential);
        } catch (DecryptException $exception) {
            throw new InvalidIntegrationCredentialSecret(previous: $exception);
        }

        $secret = base64_decode($secretBase64, true);

        if ($secret === false || strlen($secret) !== 32) {
            throw new InvalidIntegrationCredentialSecret;
        }

        return $secret;
    }

    private function isUsable(IntegrationCredential $credential): bool
    {
        if ($credential->status === IntegrationCredential::STATUS_ACTIVE) {
            return true;
        }

        return $credential->status === IntegrationCredential::STATUS_DRAINING
            && $credential->expires_at !== null
            && $credential->expires_at->isFuture();
    }

    private function unauthorized(string $code, string $message): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
        ], 401);
    }
}
