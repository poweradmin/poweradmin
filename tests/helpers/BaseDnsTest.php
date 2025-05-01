<?php

namespace TestHelpers;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Base DNS test class with common setup for all DNS-related tests
 */
class BaseDnsTest extends TestCase
{
    protected Dns $dnsInstance;

    protected function setUp(): void
    {
        $dbMock = $this->createMock(PDOLayer::class);
        $configMock = $this->createMock(ConfigurationManager::class);

        // Configure the mock to return expected values
        $configMock->method('get')
            ->willReturnCallback(function ($group, $key) {
                // For DNS tests
                if ($group === 'dns' && $key === 'strict_tld_check') {
                    return true;
                }
                if ($group === 'dns' && $key === 'top_level_tld_check') {
                    return true;
                }

                // For database tests
                if ($group === 'database' && $key === 'pdns_name') {
                    return 'pdns';  // Mock database name for tests
                }

                // Default return value
                return null;
            });

        // Mock database queries for DNS record validation tests
        $dbMock->method('quote')
            ->willReturnCallback(function ($value, $type) {
                if ($type === 'text') {
                    return "'$value'";
                }
                if ($type === 'integer') {
                    return $value;
                }
                return "'$value'";
            });

        $dbMock->method('queryOne')
            ->willReturnCallback(function ($query) {
                // Mock CNAME exists check
                if (strpos($query, "TYPE = 'CNAME'") !== false) {
                    if (strpos($query, "'existing.cname.example.com'") !== false) {
                        return ['id' => 123]; // Record exists
                    }
                }

                // Mock MX/NS check for CNAME validation
                if (strpos($query, "type = 'MX'") !== false || strpos($query, "type = 'NS'") !== false) {
                    if (strpos($query, "'invalid.cname.target'") !== false) {
                        return ['id' => 123]; // Record exists - makes CNAME invalid
                    }
                }

                // Mock target is alias check
                if (strpos($query, "TYPE = 'CNAME'") !== false) {
                    if (strpos($query, "'alias.example.com'") !== false) {
                        return ['id' => 456]; // Record exists - CNAME exists for target
                    }
                }

                return null; // No record found by default
            });

        // Create a Dns instance with mocked dependencies for tests
        $this->dnsInstance = new Dns($dbMock, $configMock);
    }
}
