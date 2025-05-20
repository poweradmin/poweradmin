<?php

namespace unit;

use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Mock configuration class for testing
 */
class MockConfiguration implements ConfigurationInterface
{
    private array $config;

    public function __construct()
    {
        $this->config = [
            'dns' => [
                'ns1' => 'ns1.example.com',
                'ns2' => 'ns2.example.com',
                'ns3' => 'ns3.example.com',
                'ns4' => 'ns4.example.com',
                'hostmaster' => 'hostmaster.example.com',
                'soa_refresh' => 28800,
                'soa_retry' => 7200,
                'soa_expire' => 604800,
                'soa_minimum' => 86400
            ]
        ];
    }

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        return $this->config[$group][$key] ?? $default;
    }

    public function getGroup(string $group): array
    {
        return $this->config[$group] ?? [];
    }

    public function getAll(): array
    {
        return $this->config;
    }
}
