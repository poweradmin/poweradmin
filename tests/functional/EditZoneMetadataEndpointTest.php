<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Functional;

use Exception;
use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Boot\BootOptions;
use Poweradmin\Application\Boot\Kernel;
use Poweradmin\Domain\Database\PdnsTable;
use Poweradmin\Domain\Database\TableNameService;
use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Infrastructure\Database\BackendModeMarker;
use Poweradmin\Infrastructure\Repository\DbZoneMetadataStore;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;

class EditZoneMetadataEndpointTest extends TestCase
{
    public function testMetadataReadEndpointRendersAllKindsWhenApiIsDisabled(): void
    {
        if (!is_file($this->getProjectRoot() . '/config/settings.php')) {
            $this->markTestSkipped('config/settings.php is missing, so there is no test database to use.');
        }

        $zoneName = 'metadata-endpoint-test-' . bin2hex(random_bytes(4)) . '.example';

        try {
            [$metadataStore, $zoneId] = $this->createMetadataStoreForTestZone($zoneName);

            $metadataStore->replaceAll($zoneId, $zoneName, $this->buildAllMetadataRows(), []);

            $output = $this->runEndpointRequest(
                'GET',
                '/zones/' . $zoneId . '/metadata',
                [],
                null,
                [
                    'dns' => ['backend' => 'sql'],
                    'pdns_api' => [
                        'url' => '',
                        'key' => '',
                        'server_name' => 'localhost',
                    ],
                ]
            );

            $this->assertStringContainsString('Edit Zone Metadata', $output);

            foreach (array_keys($this->getMetadataDefinitions()) as $kind) {
                $this->assertStringContainsString($kind, $output);
            }

            $this->assertStringContainsString('X-ENDPOINT-META', $output);
            $this->assertStringContainsString('axfr-tsig-key', $output);
            $this->assertStringContainsString('/opt/pdns/axfr.lua', $output);
        } finally {
            $this->deleteTestZone($zoneName);
        }
    }

    public function testMetadataWriteEndpointStoresAllKindsViaSql(): void
    {
        if (!is_file($this->getProjectRoot() . '/config/settings.php')) {
            $this->markTestSkipped('config/settings.php is missing, so there is no test database to use.');
        }

        $zoneName = 'metadata-endpoint-test-' . bin2hex(random_bytes(4)) . '.example';

        try {
            [$metadataStore, $zoneId] = $this->createMetadataStoreForTestZone($zoneName);

            $token = 'csrf-token-' . bin2hex(random_bytes(6));
            $submittedRows = $this->buildAllMetadataRows();
            $this->runEndpointRequest(
                'POST',
                '/zones/' . $zoneId . '/metadata',
                [
                    '_token' => $token,
                    'metadata' => $this->buildSubmittedMetadataPayload($submittedRows),
                ],
                $token,
                [
                    'dns' => ['backend' => 'sql'],
                    'pdns_api' => [
                        'url' => '',
                        'key' => '',
                        'server_name' => 'localhost',
                    ],
                ]
            );

            $rows = $metadataStore->load($zoneId, $zoneName);
            $actual = [];
            foreach ($rows as $row) {
                $actual[$row['kind']][] = $row['content'];
            }

            $expected = [];
            foreach ($submittedRows as $row) {
                $expected[$row['kind']][] = $row['content'];
            }

            ksort($actual);
            ksort($expected);

            foreach ($actual as &$values) {
                sort($values);
            }
            unset($values);

            foreach ($expected as &$values) {
                sort($values);
            }
            unset($values);

            $this->assertSame($expected, $actual);
        } finally {
            $this->deleteTestZone($zoneName);
        }
    }

    private function getMetadataDefinitions(): array
    {
        return MetadataDefinitions::DEFINITIONS;
    }

    private function buildAllMetadataRows(): array
    {
        $rows = [];

        foreach ($this->getMetadataDefinitions() as $kind => $definition) {
            foreach ($this->getValuesForKind($kind, (bool) ($definition['multi'] ?? false)) as $value) {
                $rows[] = [
                    'kind' => $kind,
                    'content' => $value,
                ];
            }
        }

        $rows[] = [
            'kind' => 'X-ENDPOINT-META',
            'content' => 'custom-value',
        ];

        return $rows;
    }

    private function buildSubmittedMetadataPayload(array $rows): array
    {
        $payload = [];

        foreach ($rows as $row) {
            $isCustom = !array_key_exists($row['kind'], $this->getMetadataDefinitions());
            $payload[] = [
                'kind_key' => $isCustom ? '__CUSTOM__' : $row['kind'],
                'custom_kind' => $isCustom ? $row['kind'] : '',
                'content' => $row['content'],
            ];
        }

        return $payload;
    }

    private function getValuesForKind(string $kind, bool $isMulti): array
    {
        return match ($kind) {
            'ALLOW-AXFR-FROM' => ['192.0.2.10', '192.0.2.11'],
            'ALLOW-DNSUPDATE-FROM' => ['192.0.2.20/32', '198.51.100.0/24'],
            'ALSO-NOTIFY' => ['198.51.100.10:5300', '198.51.100.11:5300'],
            'TSIG-ALLOW-AXFR' => ['axfr-key-1', 'axfr-key-2'],
            'FORWARD-DNSUPDATE', 'IXFR', 'NOTIFY-DNSUPDATE', 'NSEC3NARROW', 'PRESIGNED',
            'PUBLISH-CDNSKEY', 'SIGNALING-ZONE', 'SLAVE-RENOTIFY', 'API-RECTIFY',
            'ENABLE-LUA-RECORDS' => ['1'],
            'RFC1123-CONFORMANCE' => ['0'],
            'AXFR-SOURCE' => ['192.0.2.30'],
            'GSS-ACCEPTOR-PRINCIPAL' => ['DNS/ns1.example.com@REALM'],
            'GSS-ALLOW-AXFR-PRINCIPAL' => ['host/ns1.example.com@REALM'],
            'SOA-EDIT-DNSUPDATE' => ['DEFAULT'],
            'SOA-EDIT' => ['INCEPTION-INCREMENT'],
            'TSIG-ALLOW-DNSUPDATE' => ['update-key-name'],
            'AXFR-MASTER-TSIG' => ['axfr-tsig-key'],
            'LUA-AXFR-SCRIPT' => ['/opt/pdns/axfr.lua'],
            'NSEC3PARAM' => ['1 0 0 -'],
            'PUBLISH-CDS' => ['2'],
            'SOA-EDIT-API' => ['DEFAULT'],
            default => [$isMulti ? $kind . '-value-1' : $kind . '-value'],
        };
    }

    private function createMetadataStoreForTestZone(string $zoneName): array
    {
        $this->createTestZone($zoneName);

        [$context, $db] = $this->bootDatabase();
        $config = $context->config;
        $metadataStore = new DbZoneMetadataStore($db, $config);

        $zoneId = (new SqlDomainRepository($db, $config))->getDomainIdByName($zoneName);
        $this->assertNotNull($zoneId);

        return [$metadataStore, (int) $zoneId];
    }

    /**
     * Boot the kernel and connect, skipping the test when the database is unreachable or when
     * the SQL backend (which the endpoint subprocess is forced into) may not use it because the
     * database was last used in API mode. DatabaseService::connect() reports an unreachable
     * database as a RuntimeException with a "Database connection failed" message; any other
     * boot failure propagates instead of being reported as skipped.
     *
     * @return array{0: \Poweradmin\Application\Boot\BootContext, 1: PDO}
     */
    private function bootDatabase(): array
    {
        try {
            $context = Kernel::boot(BootOptions::Script);
            $db = $context->database();
        } catch (Exception $e) {
            if (!str_starts_with($e->getMessage(), 'Database connection failed')) {
                throw $e;
            }
            $this->markTestSkipped('test database unreachable: ' . $e->getMessage());
        }

        $domainsTable = (new TableNameService($context->config))->getTable(PdnsTable::DOMAINS);
        $refusal = BackendModeMarker::sqlModeRefusal($db, $domainsTable);
        if ($refusal !== null) {
            $this->markTestSkipped('test database cannot be used in SQL mode: ' . $refusal);
        }

        return [$context, $db];
    }

    private function createTestZone(string $zoneName): void
    {
        $this->deleteTestZone($zoneName);

        [$context, $db] = $this->bootDatabase();
        $tables = new TableNameService($context->config);
        $domains = $tables->getTable(PdnsTable::DOMAINS);
        $records = $tables->getTable(PdnsTable::RECORDS);

        $stmt = $db->prepare("INSERT INTO $domains (name, type) VALUES (:name, 'NATIVE')");
        $stmt->bindValue(':name', $zoneName);
        $stmt->execute();

        $zoneId = (new SqlDomainRepository($db, $context->config))->getDomainIdByName($zoneName);
        $this->assertNotNull($zoneId);

        $stmt = $db->prepare(
            "INSERT INTO $records (domain_id, name, type, content, ttl) VALUES (:domain_id, :name, 'SOA', :content, 3600)"
        );
        $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
        $stmt->bindValue(':name', $zoneName);
        $stmt->bindValue(':content', "ns1.$zoneName hostmaster.$zoneName 1 10800 3600 604800 3600");
        $stmt->execute();
    }

    private function deleteTestZone(string $zoneName): void
    {
        try {
            [$context, $db] = $this->bootDatabase();
            $tables = new TableNameService($context->config);

            $zoneId = (new SqlDomainRepository($db, $context->config))->getDomainIdByName($zoneName);
            if ($zoneId === null) {
                return;
            }

            foreach ([PdnsTable::RECORDS, PdnsTable::DOMAINMETADATA] as $table) {
                $stmt = $db->prepare('DELETE FROM ' . $tables->getTable($table) . ' WHERE domain_id = :domain_id');
                $stmt->bindValue(':domain_id', $zoneId, PDO::PARAM_INT);
                $stmt->execute();
            }

            $stmt = $db->prepare('DELETE FROM ' . $tables->getTable(PdnsTable::DOMAINS) . ' WHERE id = :id');
            $stmt->bindValue(':id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\Throwable) {
            // Cleanup must not replace the failure or skip that got the test here
            return;
        }
    }

    private function runEndpointRequest(
        string $method,
        string $uri,
        array $payload = [],
        ?string $csrfToken = null,
        array $configOverrides = []
    ): string {
        $scriptPath = tempnam(sys_get_temp_dir(), 'pa-metadata-endpoint-');
        if ($scriptPath === false) {
            $this->fail('Failed to create temporary endpoint script.');
        }

        $configPath = $this->createTemporaryConfig($configOverrides);

        $encodedPayload = var_export($payload, true);
        $encodedUri = var_export($uri, true);
        $encodedMethod = var_export($method, true);
        $encodedToken = var_export($csrfToken ?? '', true);
        $encodedConfigPath = var_export($configPath, true);

        $script = <<<PHP
<?php
putenv('PA_CONFIG_PATH=' . {$encodedConfigPath});
require {$this->exportPhpString($this->getProjectRoot() . '/vendor/autoload.php')};
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
\$_SESSION = [];
\$_SESSION['userid'] = 1;
\$_SESSION['userlogin'] = 'admin';
\$_SESSION['name'] = 'Administrator';
\$_SESSION['auth_method_used'] = 'oidc';
\$_SESSION['authenticated'] = true;
\$_SESSION['lastmod'] = time();
\$_SESSION['csrf_token'] = {$encodedToken};
\$_GET = [];
\$_POST = [];
\$_REQUEST = [];
\$_SERVER['REQUEST_METHOD'] = {$encodedMethod};
\$_SERVER['REQUEST_URI'] = {$encodedUri};
\$_SERVER['SERVER_NAME'] = 'localhost';
\$_SERVER['SERVER_PORT'] = '80';
\$_SERVER['HTTPS'] = '';
\$_SERVER['HTTP_HOST'] = 'localhost';
\$payload = {$encodedPayload};
if ({$encodedMethod} === 'POST') {
    \$_POST = \$payload;
    \$_REQUEST = \$payload;
} else {
    \$_GET = \$payload;
    \$_REQUEST = \$payload;
}
\$router = new \Poweradmin\Application\Routing\SymfonyRouter(\Poweradmin\Application\Boot\Kernel::boot(true));
ob_start();
\$router->process();
echo ob_get_clean();
PHP;

        file_put_contents($scriptPath, $script);

        try {
            $output = [];
            $exitCode = 0;
            exec('php ' . escapeshellarg($scriptPath) . ' 2>&1', $output, $exitCode);

            $this->assertSame(0, $exitCode, "Endpoint script failed:\n" . implode("\n", $output));

            return implode("\n", $output);
        } finally {
            @unlink($scriptPath);
            @unlink($configPath);
        }
    }

    private function createTemporaryConfig(array $overrides): string
    {
        $baseConfig = require $this->getProjectRoot() . '/config/settings.php';

        foreach ($overrides as $group => $values) {
            $baseConfig[$group] = array_merge($baseConfig[$group] ?? [], $values);
        }

        $path = tempnam(sys_get_temp_dir(), 'pa-config-');
        if ($path === false) {
            $this->fail('Failed to create temporary config file.');
        }

        $content = "<?php\n\nreturn " . var_export($baseConfig, true) . ";\n";
        file_put_contents($path, $content);

        return $path;
    }

    private function getProjectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function exportPhpString(string $value): string
    {
        return var_export($value, true);
    }
}
