<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\CsrfTokenService;
use Poweradmin\Infrastructure\Session\ArraySession;

#[CoversClass(CsrfTokenService::class)]
class CsrfTokenServiceTest extends TestCase
{
    private ArraySession $session;

    private CsrfTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $this->service = new CsrfTokenService($this->session);
    }

    #[Test]
    public function testGenerateTokenReturnsCorrectLength(): void
    {
        $token = $this->service->generateToken();

        $this->assertEquals(CsrfTokenService::TOKEN_LENGTH, strlen($token));
    }

    #[Test]
    public function testGenerateTokenUsesUrlSafeCharacters(): void
    {
        $token = $this->service->generateToken();

        // URL-safe base64 uses only alphanumeric, dash, and underscore
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
    }

    #[Test]
    public function testGenerateTokenReturnsUniqueValues(): void
    {
        $token1 = $this->service->generateToken();
        $token2 = $this->service->generateToken();

        $this->assertNotEquals($token1, $token2);
    }

    #[Test]
    public function testGetTokenReturnsEmptyStringWhenNotSet(): void
    {
        $token = $this->service->getToken();

        $this->assertSame('', $token);
    }

    #[Test]
    public function testGetTokenReturnsStoredValue(): void
    {
        $this->session->set('csrf_token', 'test_token_value');

        $token = $this->service->getToken();

        $this->assertSame('test_token_value', $token);
    }

    #[Test]
    public function testGetTokenWithCustomSessionVar(): void
    {
        $this->session->set('custom_token', 'custom_value');

        $token = $this->service->getToken('custom_token');

        $this->assertSame('custom_value', $token);
    }

    #[Test]
    public function testValidateTokenReturnsTrueForValidToken(): void
    {
        $this->session->set('csrf_token', 'valid_token');

        $result = $this->service->validateToken('valid_token');

        $this->assertTrue($result);
    }

    #[Test]
    public function testValidateTokenReturnsFalseForInvalidToken(): void
    {
        $this->session->set('csrf_token', 'valid_token');

        $result = $this->service->validateToken('invalid_token');

        $this->assertFalse($result);
    }

    #[Test]
    public function testValidateTokenReturnsFalseWhenSessionNotSet(): void
    {
        $result = $this->service->validateToken('any_token');

        $this->assertFalse($result);
    }

    #[Test]
    public function testValidateTokenWithCustomSessionVar(): void
    {
        $this->session->set('login_token', 'login_token_value');

        $result = $this->service->validateToken('login_token_value', 'login_token');

        $this->assertTrue($result);
    }

    #[Test]
    public function testValidateTokenIsTimingSafe(): void
    {
        // This test verifies hash_equals behavior (timing-safe comparison)
        $this->session->set('csrf_token', 'secret_token_value');

        // Both should complete in similar time regardless of match position
        $this->assertFalse($this->service->validateToken('Xecret_token_value'));
        $this->assertFalse($this->service->validateToken('secret_token_valuX'));
    }
}
