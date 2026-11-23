<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ApiKeyService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\ApiKeyAuthenticationMiddleware;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * An api_key query value that is not a string means no key was sent: the request is
 * unauthenticated, never an exception.
 */
#[CoversClass(ApiKeyAuthenticationMiddleware::class)]
class ApiKeyAuthenticationMiddlewareQueryKeyTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_API_KEY']);
    }

    private function middleware(?ApiKeyService $service = null): ApiKeyAuthenticationMiddleware
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn(true);

        $reflection = new ReflectionClass(ApiKeyAuthenticationMiddleware::class);
        $middleware = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('config')->setValue($middleware, $config);
        $reflection->getProperty('apiKeyService')->setValue($middleware, $service ?? $this->createMock(ApiKeyService::class));

        return $middleware;
    }

    public function testAnArrayApiKeyIsTreatedAsAbsent(): void
    {
        $service = $this->createMock(ApiKeyService::class);
        $service->expects($this->never())->method('authenticate');
        $request = Request::create('/api/v2/zones?api_key[]=x&api_key[]=y');

        $this->assertFalse($this->middleware($service)->process($request));
        $this->assertSame(0, $this->middleware($service)->getAuthenticatedUserId($request));
        $this->assertNull($this->middleware($service)->getAuthenticatedApiKeyId($request));
        $this->assertNull($this->middleware($service)->getApiKeyScope($request));
    }

    public function testANestedArrayApiKeyIsTreatedAsAbsent(): void
    {
        $service = $this->createMock(ApiKeyService::class);
        $service->expects($this->never())->method('authenticate');

        $this->assertFalse($this->middleware($service)->process(Request::create('/api/v2/zones?api_key[a][b]=x')));
    }

    public function testAStringApiKeyIsStillAccepted(): void
    {
        $service = $this->createMock(ApiKeyService::class);
        $service->expects($this->once())->method('authenticate')->with('secret')->willReturn(true);

        $this->assertTrue($this->middleware($service)->process(Request::create('/api/v2/zones?api_key=secret')));
    }

    public function testAnEmptyApiKeyIsTreatedAsAbsent(): void
    {
        $service = $this->createMock(ApiKeyService::class);
        $service->expects($this->never())->method('authenticate');

        $this->assertFalse($this->middleware($service)->process(Request::create('/api/v2/zones?api_key=')));
    }
}
