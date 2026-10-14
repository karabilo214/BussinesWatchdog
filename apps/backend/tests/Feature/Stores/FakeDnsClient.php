<?php

namespace Tests\Feature\Stores;

use App\Support\Network\DnsClient;

class FakeDnsClient implements DnsClient
{
    /**
     * @var array<string, list<string>>
     */
    public array $ips = [];

    /**
     * @var array<string, list<string>>
     */
    public array $txt = [];

    public function resolveIps(string $host): array
    {
        return $this->ips[$host] ?? [];
    }

    public function txtRecords(string $name): array
    {
        return $this->txt[$name] ?? [];
    }
}
