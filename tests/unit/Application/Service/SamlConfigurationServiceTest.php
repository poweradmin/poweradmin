<?php

namespace unit\Application\Service;

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
                ['interface', 'application_url', '', 'https://localhost'],
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Provider invalid_provider not found or invalid');

        $this->service->generateOneLoginSettings('invalid_provider');
    }

    public function testGenerateOneLoginSettingsPrefersApplicationUrlOverBaseUrl(): void
    {
        // Both keys set: application_url is the canonical setting and must win
        // so the SP metadata advertised to the IdP cannot be quietly diverted
        // through the undocumented base_url path.
        $this->mockConfig->method('get')
            ->willReturnMap([
                ['saml', 'sp', [], ['x509cert' => 'cert', 'private_key' => 'key']],
                ['saml', 'providers', [], [
                    'azure' => [
                        'entity_id' => 'https://login.microsoftonline.com/tenant/',
                        'sso_url' => 'https://login.microsoftonline.com/tenant/saml2',
                    ],
                ]],
                ['interface', 'application_url', '', 'https://canonical.example/poweradmin'],
                ['interface', 'base_url', '', 'https://legacy.example'],
            ]);

        $settings = $this->service->generateOneLoginSettings('azure');

        $this->assertStringStartsWith('https://canonical.example/poweradmin/', $settings['sp']['entityId']);
        $this->assertStringStartsWith('https://canonical.example/poweradmin/', $settings['sp']['assertionConsumerService']['url']);
    }

    public function testGenerateOneLoginSettingsRefusesForgedHostWhenApplicationUrlIsUnset(): void
    {
        // SERVER_NAME follows the client Host header under FrankenPHP and Apache
        // defaults, so it must never reach the entityID/ACS advertised to the IdP
        $_SERVER['SERVER_NAME'] = 'evil.attacker.test';
        $_SERVER['HTTP_HOST'] = 'evil.attacker.test';

        $this->mockConfig->method('get')
            ->willReturnMap([
                ['saml', 'sp', [], ['x509cert' => 'cert', 'private_key' => 'key']],
                ['saml', 'providers', [], [
                    'azure' => [
                        'entity_id' => 'https://login.microsoftonline.com/tenant/',
                        'sso_url' => 'https://login.microsoftonline.com/tenant/saml2',
                    ],
                ]],
                ['interface', 'application_url', '', ''],
                ['interface', 'base_url', '', ''],
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('interface.application_url must be configured');

        $this->service->generateOneLoginSettings('azure');
    }

    public function testServiceProviderConfigKeepsExplicitUrlsWithoutApplicationUrl(): void
    {
        // Explicit sp URLs need no derivation, so they must not be forced to
        // configure application_url just to read the SP config back
        $this->mockConfig->method('get')
            ->willReturnMap([
                ['saml', 'sp', [], [
                    'entity_id' => 'https://sp.example.com/saml/metadata',
                    'assertion_consumer_service_url' => 'https://sp.example.com/saml/acs',
                    'single_logout_service_url' => 'https://sp.example.com/saml/sls',
                ]],
                ['interface', 'application_url', '', ''],
                ['interface', 'base_url', '', ''],
            ]);

        $config = $this->service->getServiceProviderConfig();

        $this->assertSame('https://sp.example.com/saml/metadata', $config['entity_id']);
        $this->assertSame('https://sp.example.com/saml/acs', $config['assertion_consumer_service_url']);
    }

    public function testServiceProviderConfigDerivesUrlsShippedAsEmptyStrings(): void
    {
        // settings.defaults.php ships the three sp URLs as '', and the defaults
        // are merged into the settings tree, so the keys are always present
        $this->mockConfig->method('get')
            ->willReturnMap([
                ['saml', 'sp', [], [
                    'entity_id' => '',
                    'assertion_consumer_service_url' => '',
                    'single_logout_service_url' => '',
                    'x509cert' => '',
                    'private_key' => '',
                ]],
                ['interface', 'application_url', '', 'https://sp.example.com/poweradmin'],
                ['interface', 'base_url', '', ''],
            ]);

        $config = $this->service->getServiceProviderConfig();

        $this->assertSame('https://sp.example.com/poweradmin/saml/metadata', $config['entity_id']);
        $this->assertSame('https://sp.example.com/poweradmin/saml/acs', $config['assertion_consumer_service_url']);
        $this->assertSame('https://sp.example.com/poweradmin/saml/sls', $config['single_logout_service_url']);
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
}
