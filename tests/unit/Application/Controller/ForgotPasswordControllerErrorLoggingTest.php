<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\ForgotPasswordController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\PasswordResetService;
use Poweradmin\Application\Service\RecaptchaService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Poweradmin\Infrastructure\Utility\UserAgentService;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * A stack trace prints call arguments, and this flow carries an email address and
 * runs next to the password reset itself, so the error log records where the failure
 * came from rather than how it got there.
 */
class ForgotPasswordControllerErrorLoggingTest extends TestCase
{
    private const EMAIL = 'user@example.com';

    /** @var array{0: string, 1: array}|null */
    private ?array $loggedError = null;

    public function testUnexpectedFailureLogsTheOriginOfTheException(): void
    {
        $thrownAtLine = __LINE__ + 1;
        $failure = new RuntimeException('mail transport unavailable');

        $this->runResetRequestFailingWith($failure);

        $this->assertNotNull($this->loggedError, 'the error path did not log anything');
        [$message, $context] = $this->loggedError;

        $this->assertSame('Password reset failed - unexpected error', $message);
        $this->assertSame(__FILE__ . ':' . $thrownAtLine, $context['origin']);
    }

    /**
     * The exact key set is the assertion that matters: asserting the absence of a
     * 'trace' key alone would be defeated by any other name for the same thing.
     */
    public function testTheLoggedContextCarriesNoStackTrace(): void
    {
        $this->runResetRequestFailingWith(new RuntimeException('mail transport unavailable'));

        $this->assertNotNull($this->loggedError);
        $context = $this->loggedError[1];

        $this->assertSame(['email', 'ip', 'error', 'origin', 'timestamp'], array_keys($context));

        foreach ($context as $key => $value) {
            $this->assertStringNotContainsString('#0 ', (string) $value, "context key '$key' carries a stack trace");
        }
    }

    private function runResetRequestFailingWith(RuntimeException $failure): void
    {
        $controller = new TestableForgotPasswordController();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            function (string $message, array $context): void {
                $this->loggedError = [$message, $context];
            }
        );
        $controller->setLogger($logger);

        // Off by default in the devcontainer instances too; leaving it on would pull
        // the session-backed CSRF check into a test that is not about CSRF.
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) =>
                $group === 'security' && $key === 'global_token_validation' ? false : $default
        );
        $controller->setConfig($config);

        $passwordResetService = $this->createMock(PasswordResetService::class);
        $passwordResetService->method('canUserResetPassword')
            ->willReturn(['allowed' => true, 'auth_method' => 'sql']);
        $passwordResetService->method('createResetRequest')->willThrowException($failure);

        $request = $this->createMock(Request::class);
        $request->method('getPostParam')->willReturnCallback(
            fn(string $name, $default = null) => $name === 'email' ? self::EMAIL : $default
        );

        $ipRetriever = $this->createMock(IpAddressRetriever::class);
        $ipRetriever->method('getClientIp')->willReturn('203.0.113.7');

        $userAgentService = $this->createMock(UserAgentService::class);
        $userAgentService->method('getUserAgent')->willReturn('phpunit');

        $recaptchaService = $this->createMock(RecaptchaService::class);
        $recaptchaService->method('isEnabled')->willReturn(false);

        $this->setPrivate($controller, 'passwordResetService', $passwordResetService);
        $this->setPrivate($controller, 'request', $request);
        $this->setPrivate($controller, 'ipRetriever', $ipRetriever);
        $this->setPrivate($controller, 'userAgentService', $userAgentService);
        $this->setPrivate($controller, 'recaptchaService', $recaptchaService);
        $this->setPrivate($controller, 'csrfTokenService', $this->createMock(CsrfTokenService::class));

        $method = (new ReflectionClass(ForgotPasswordController::class))
            ->getMethod('handlePasswordResetRequest');
        $method->setAccessible(true);
        $method->invoke($controller);
    }

    private function setPrivate(object $controller, string $name, object $value): void
    {
        $property = (new ReflectionClass(ForgotPasswordController::class))->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($controller, $value);
    }
}
