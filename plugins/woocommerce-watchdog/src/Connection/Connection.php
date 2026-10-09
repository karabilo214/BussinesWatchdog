<?php

namespace BusinessWatchdog\WooCommerce\Connection;

use BusinessWatchdog\WooCommerce\Security\SecretBox;
use BusinessWatchdog\WooCommerce\Storage\State;

final class Connection
{
    public const STATE_KEY = 'connection';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_SUSPENDED = 'suspended';

    private array $data;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function current(): ?self
    {
        $data = State::get(self::STATE_KEY);

        return is_array($data) && isset($data['integration_id'], $data['key_id'], $data['secret']) ? new self($data) : null;
    }

    public static function store(string $endpoint, string $integrationId, string $keyId, string $secretBase64): self
    {
        $data = [
            'endpoint' => rtrim($endpoint, '/'),
            'integration_id' => $integrationId,
            'key_id' => $keyId,
            'secret' => SecretBox::seal($secretBase64),
            'status' => self::STATUS_CONNECTED,
            'connected_at' => gmdate('c'),
        ];
        State::set(self::STATE_KEY, $data);

        return new self($data);
    }

    public static function forget(): void
    {
        State::delete(self::STATE_KEY);
    }

    public function endpoint(): string
    {
        return (string) $this->data['endpoint'];
    }

    public function integrationId(): string
    {
        return (string) $this->data['integration_id'];
    }

    public function keyId(): string
    {
        return (string) $this->data['key_id'];
    }

    public function secretBytes(): ?string
    {
        $base64 = SecretBox::open((array) $this->data['secret']);

        if ($base64 === null) {
            return null;
        }

        $bytes = base64_decode($base64, true);

        return $bytes !== false && strlen($bytes) === 32 ? $bytes : null;
    }

    public function status(): string
    {
        return (string) ($this->data['status'] ?? self::STATUS_CONNECTED);
    }

    public function suspend(string $reason): void
    {
        $this->data['status'] = self::STATUS_SUSPENDED;
        $this->data['suspended_reason'] = $reason;
        $this->data['suspended_at'] = gmdate('c');
        State::set(self::STATE_KEY, $this->data);
    }

    public function replaceKey(string $keyId, string $secretBase64): void
    {
        $this->data['key_id'] = $keyId;
        $this->data['secret'] = SecretBox::seal($secretBase64);
        $this->data['rotated_at'] = gmdate('c');
        State::set(self::STATE_KEY, $this->data);
    }

    public function publicSummary(): array
    {
        return [
            'endpoint' => $this->endpoint(),
            'integration_id' => $this->integrationId(),
            'key_id_suffix' => substr($this->keyId(), -6),
            'status' => $this->status(),
            'suspended_reason' => $this->data['suspended_reason'] ?? null,
            'connected_at' => $this->data['connected_at'] ?? null,
            'rotated_at' => $this->data['rotated_at'] ?? null,
            'secret_readable' => $this->secretBytes() !== null,
        ];
    }
}
