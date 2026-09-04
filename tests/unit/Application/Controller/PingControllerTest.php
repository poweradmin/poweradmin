<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

class PingControllerTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $settings
     */
    private function controller(array $settings): TestablePingController
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null): mixed => $settings[$group][$key] ?? $default
        );

        return new TestablePingController($config);
    }

    public function testDisabledByDefaultAnswersNotFound(): void
    {
        $response = $this->controller([])->buildResponse();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $response->getContent());
    }

    public function testEnabledAnswersOk(): void
    {
        $response = $this->controller(['health' => ['ping_enabled' => true]])->buildResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
        $this->assertStringContainsString('text/plain', (string)$response->headers->get('Content-Type'));
    }

    /**
     * Liveness must not depend on the health endpoint's own flag.
     */
    public function testPingFlagIsIndependentOfTheHealthFlag(): void
    {
        $response = $this->controller(['health' => ['enabled' => true]])->buildResponse();

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testResponseIsNotCacheable(): void
    {
        $response = $this->controller(['health' => ['ping_enabled' => true]])->buildResponse();

        $this->assertStringContainsString('no-store', (string)$response->headers->get('Cache-Control'));
    }
}
