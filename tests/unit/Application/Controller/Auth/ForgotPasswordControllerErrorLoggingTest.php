<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Controller\Auth;

use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Controller\Auth\ForgotPasswordController;
use Poweradmin\Application\Service\PasswordResetService;
use Poweradmin\Application\Service\RecaptchaService;
use Psr\Log\LoggerInterface;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * A stack trace prints call arguments, and this flow carries an email address and
 * runs next to the password reset itself, so the error log records where the failure
 * came from rather than how it got there.
 */
class ForgotPasswordControllerErrorLoggingTest extends SeamControllerTestCase
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
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            function (string $message, array $context): void {
                $this->loggedError = [$message, $context];
            }
        );

        $service = $this->createMock(PasswordResetService::class);
        $service->method('canUserResetPassword')->willReturn(['allowed' => true, 'auth_method' => 'sql']);
        $service->method('createResetRequest')->willThrowException($failure);

        $recaptchaService = $this->createMock(RecaptchaService::class);
        $recaptchaService->method('isEnabled')->willReturn(false);
        $this->factory->method('recaptchaService')->willReturn($recaptchaService);
        $this->factory->method('clientContext')->willReturn(new ClientContext('203.0.113.7', 'phpunit', 'Unknown', false));

        // Leaving this on would pull the session-backed CSRF check into a test that
        // is not about CSRF.
        $this->post(['email' => self::EMAIL]);
        $controller = new TestableForgotPasswordController([], $this->environment($this->configure(['security' => ['global_token_validation' => false]])));
        $controller->setLogger($logger);
        // Composed with new rather than through the factory, so it is planted
        (new ReflectionProperty(ForgotPasswordController::class, 'passwordResetService'))->setValue($controller, $service);

        (new ReflectionMethod(ForgotPasswordController::class, 'handlePasswordResetRequest'))->invoke($controller);
    }
}
