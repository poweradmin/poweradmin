<?php

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Service\SamlConfigurationService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use RuntimeException;

class SamlConfigurationServiceTest extends TestCase
{
    private SamlConfigurationService $service;
    private ConfigurationManager|MockObject $mockConfig;
    private Logger|MockObject $mockLogger;

    protected function setUp(): void
    {
        $this->mockConfig = $this->createMock(ConfigurationManager::class);
        $this->mockLogger = $this->createMock(Logger::class);
        $this->service = new SamlConfigurationService($this->mockConfig, $this->mockLogger);
    }

    public function testGenerateOneLoginSettingsWithInvalidProvider(): void
    {
        $this->mockConfig->method('get')
            ->willReturnMap([
                ['saml', 'sp', [], []],
                ['saml', 'providers', [], []],
                ['interface', 'base_url', '', 'https://localhost'],
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Provider invalid_provider not found or invalid');

        $this->service->generateOneLoginSettings('invalid_provider');
    }

    public function testGetProviderConfigReturnsNullForMissingProvider(): void
    {
        $this->mockConfig->method('get')
            ->with('saml', 'providers', [])
            ->willReturn([]);

        $result = $this->service->getProviderConfig('missing_provider');

        $this->assertNull($result);
    }

    public function testGetProviderConfigReturnsConfigForValidProvider(): void
    {
        $expectedConfig = [
            'name' => 'Test Provider',
            'entity_id' => 'https://idp.example.com/metadata',
            'sso_url' => 'https://idp.example.com/sso',
        ];

        $this->mockConfig->method('get')
            ->with('saml', 'providers', [])
            ->willReturn(['test_provider' => $expectedConfig]);

        $result = $this->service->getProviderConfig('test_provider');

        $this->assertEquals($expectedConfig, $result);
    }

    public function testDescribeProviderConfigErrorReportsMissingProvider(): void
    {
        $this->mockConfig->method('get')
            ->with('saml', 'providers', [])
            ->willReturn([]);

        $error = $this->service->describeProviderConfigError('azure');

        $this->assertSame("provider 'azure' is not defined in saml.providers", $error);
    }

    public function testDescribeProviderConfigErrorReportsMissingRequiredField(): void
    {
        $this->mockConfig->method('get')
            ->with('saml', 'providers', [])
            ->willReturn([
                'azure' => [
                    'entity_id' => 'https://login.microsoftonline.com/tenant/',
                    // sso_url intentionally missing
                ],
            ]);

        $this->assertSame(
            "missing required field 'sso_url'",
            $this->service->describeProviderConfigError('azure')
        );
    }

    public function testDescribeProviderConfigErrorReportsMalformedX509Certificate(): void
    {
        $this->mockConfig->method('get')
            ->with('saml', 'providers', [])
            ->willReturn([
                'azure' => [
                    'entity_id' => 'https://login.microsoftonline.com/tenant/',
                    'sso_url' => 'https://login.microsoftonline.com/tenant/saml2',
                    'x509cert' => 'not-a-real-cert',
                ],
            ]);

        $this->assertSame(
            'x509cert is not a valid X.509 certificate',
            $this->service->describeProviderConfigError('azure')
        );
    }

    public function testDescribeProviderConfigErrorReturnsNullForValidConfig(): void
    {
        $this->mockConfig->method('get')
            ->with('saml', 'providers', [])
            ->willReturn([
                'azure' => [
                    'entity_id' => 'https://login.microsoftonline.com/tenant/',
                    'sso_url' => 'https://login.microsoftonline.com/tenant/saml2',
                ],
            ]);

        $this->assertNull($this->service->describeProviderConfigError('azure'));
    }

    public function testGetProviderConfigRejectsMalformedX509Certificate(): void
    {
        // Same scenario as the Azure user in #1218: provider is otherwise
        // configured, but the x509cert paste fails openssl_x509_read. The old
        // behaviour returned null silently; now it is also surfaced via
        // describeProviderConfigError above.
        $this->mockConfig->method('get')
            ->with('saml', 'providers', [])
            ->willReturn([
                'azure' => [
                    'entity_id' => 'https://login.microsoftonline.com/tenant/',
                    'sso_url' => 'https://login.microsoftonline.com/tenant/saml2',
                    'x509cert' => 'definitely-not-a-cert',
                ],
            ]);

        $this->assertNull($this->service->getProviderConfig('azure'));
    }

    public function testGetProviderConfigAcceptsHeaderlessCertWithLineBreaks(): void
    {
        // Generate a real self-signed cert for the test.
        $cert = $this->generateSelfSignedPem();

        // Strip the PEM headers and keep the existing 64-char line breaks
        // (this is the format you get from Azure portal "Certificate (Base64)
        // download"). The pre-fix code would chunk_split it again and end up
        // with malformed lines; the fix normalises whitespace first.
        $body = preg_replace('/-----(?:BEGIN|END) CERTIFICATE-----/', '', $cert);

        $this->mockConfig->method('get')
            ->with('saml', 'providers', [])
            ->willReturn([
                'azure' => [
                    'entity_id' => 'https://login.microsoftonline.com/tenant/',
                    'sso_url' => 'https://login.microsoftonline.com/tenant/saml2',
                    'x509cert' => trim($body),
                ],
            ]);

        $this->assertNull($this->service->describeProviderConfigError('azure'));
        $this->assertNotNull($this->service->getProviderConfig('azure'));
    }

    public function testGetProviderConfigAcceptsCertWithCrlfLineEndings(): void
    {
        $cert = $this->generateSelfSignedPem();
        $body = preg_replace('/-----(?:BEGIN|END) CERTIFICATE-----/', '', $cert);
        $crlfBody = str_replace("\n", "\r\n", trim($body));

        $this->mockConfig->method('get')
            ->with('saml', 'providers', [])
            ->willReturn([
                'azure' => [
                    'entity_id' => 'https://login.microsoftonline.com/tenant/',
                    'sso_url' => 'https://login.microsoftonline.com/tenant/saml2',
                    'x509cert' => $crlfBody,
                ],
            ]);

        $this->assertNotNull($this->service->getProviderConfig('azure'));
    }

    private function generateSelfSignedPem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key, 'openssl_pkey_new must succeed');

        $csr = openssl_csr_new(['commonName' => 'unit-test'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr, 'openssl_csr_new must succeed');

        $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($x509, 'openssl_csr_sign must succeed');

        $pem = '';
        $this->assertTrue(openssl_x509_export($x509, $pem));
        return $pem;
    }
}
