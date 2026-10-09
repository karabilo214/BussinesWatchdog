<?php

namespace App\Support\Network;

interface DnsClient
{
    /**
     * @return list<string>
     */
    public function resolveIps(string $host): array;

    /**
     * @return list<string>
     */
    public function txtRecords(string $name): array;
}
