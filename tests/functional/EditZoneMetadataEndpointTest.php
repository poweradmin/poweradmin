<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Poweradmin\AppInitializer;
use Poweradmin\Application\Controller\EditZoneMetadataController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use ReflectionClass;

class EditZoneMetadataEndpointTest extends TestCase
{
    public function testMetadataReadEndpointRendersAllKindsWhenApiIsDisabled(): void
    {
        if (!is_file($this->getProjectRoot() . '/config/settings.php') || trim((string) shell_exec('which pdnsutil')) === '') {
            $this->markTestSkipped('Local PowerDNS test environment is not available.');
        }

        $zoneName = 'metadata-endpoint-test-' . bin2hex(random_bytes(4)) . '.example';

        try {
            [$zoneRepository, $zoneId] = $this->createZoneRepositoryForTestZone($zoneName);

            $zoneRepository->replaceDomainMetadata($zoneId, $this->buildAllMetadataRows());

            $output = $this->runEndpointRequest(
                'GET',
                '/zones/' . $zoneId . '/metadata',
                [],
                null,
                [
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
        if (!is_file($this->getProjectRoot() . '/config/settings.php') || trim((string) shell_exec('which pdnsutil')) === '') {
            $this->markTestSkipped('Local PowerDNS test environment is not available.');
        }

        $zoneName = 'metadata-endpoint-test-' . bin2hex(random_bytes(4)) . '.example';

        try {
            [$zoneRepository, $zoneId] = $this->createZoneRepositoryForTestZone($zoneName);

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
                    'pdns_api' => [
                        'url' => '',
                        'key' => '',
                        'server_name' => 'localhost',
                    ],
                ]
            );

            $rows = $zoneRepository->getDomainMetadata($zoneId);
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
        $reflection = new ReflectionClass(EditZoneMetadataController::class);
        $constant = $reflection->getReflectionConstant('METADATA_DEFINITIONS');

        return $constant->getValue();
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
            'SOA-EDIT-DNSUPDATE', 'SOA-EDIT' => ['INCEPTION-INCREMENT'],
            'TSIG-ALLOW-DNSUPDATE' => ['update-key-name'],
            'AXFR-MASTER-TSIG' => ['axfr-tsig-key'],
            'LUA-AXFR-SCRIPT' => ['/opt/pdns/axfr.lua'],
            'NSEC3PARAM' => ['1 0 0 -'],
            'PUBLISH-CDS' => ['2'],
            'SOA-EDIT-API' => ['DEFAULT'],
            default => [$isMulti ? $kind . '-value-1' : $kind . '-value'],
        };
    }

    private function createZoneRepositoryForTestZone(string $zoneName): array
    {
        $this->createTestZone($zoneName);

        $initializer = new AppInitializer(false);
        $db = $initializer->getDb();
        $zoneRepository = new DbZoneRepository($db, ConfigurationManager::getInstance());

        $zoneId = $zoneRepository->getZoneIdByName($zoneName);
        $this->assertNotNull($zoneId);

        return [$zoneRepository, (int) $zoneId];
    }

    private function createTestZone(string $zoneName): void
    {
        $escapedZone = escapeshellarg($zoneName);
        shell_exec("pdnsutil delete-zone $escapedZone >/dev/null 2>&1 || true");
        shell_exec("pdnsutil create-zone $escapedZone " . escapeshellarg("ns1.$zoneName") . " >/dev/null 2>&1");
    }

    private function deleteTestZone(string $zoneName): void
    {
        $escapedZone = escapeshellarg($zoneName);
        shell_exec("pdnsutil delete-zone $escapedZone >/dev/null 2>&1 || true");
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
\$router = new \Poweradmin\Application\Routing\SymfonyRouter();
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
