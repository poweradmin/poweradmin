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
}
