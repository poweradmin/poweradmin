<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PDOException;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\ExternalIdentityRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\ValueObject\UserInfoInterface;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionClass;

/**
 * Provisioning wraps the user INSERT, which runs next to a password column, so the
 * failure log records where the exception came from rather than a full trace.
 */
class UserProvisioningServiceErrorLoggingTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> */
    private array $loggedErrors = [];

    public function testFailedInsertLogsTheOriginOfTheException(): void
    {
        $raisedAtLine = __LINE__ + 1;
        $failure = new PDOException('SQLSTATE[HY000]: server has gone away');

        $this->provisionFailingWith($failure, UserProvisioningService::AUTH_METHOD_LDAP);

        [$message, $context] = $this->creationFailure();

        $this->assertSame('Error creating new {method} user: {error} at {origin}', $message);
        $this->assertSame(__FILE__ . ':' . $raisedAtLine, $context['origin']);
    }

    /**
     * The message used to say "OIDC" on every path, including the LDAP and SAML ones.
     */
    public function testTheLoggedMethodIsTheOneActuallyUsed(): void
    {
        $this->provisionFailingWith(new PDOException('server has gone away'), UserProvisioningService::AUTH_METHOD_LDAP);

        $this->assertSame('LDAP', $this->creationFailure()[1]['method']);
    }

    public function testTheLoggedContextCarriesNoStackTrace(): void
    {
        $this->provisionFailingWith(new PDOException('server has gone away'), UserProvisioningService::AUTH_METHOD_LDAP);

        $context = $this->creationFailure()[1];

        $this->assertSame(['method', 'error', 'origin', 'classname'], array_keys($context));

        foreach ($context as $key => $value) {
            $this->assertStringNotContainsString('#0 ', (string) $value, "context key '$key' carries a stack trace");
        }
    }

    /** @return array{0: string, 1: array} */
    private function creationFailure(): array
    {
        foreach ($this->loggedErrors as $logged) {
            if (str_starts_with($logged[0], 'Error creating new')) {
                return $logged;
            }
        }

        $this->fail('the provisioning error path did not log a creation failure');
    }

    private function provisionFailingWith(PDOException $failure, string $authMethod): void
    {
        $service = new UserProvisioningService(
            $this->configurationWithDefaultTemplate($authMethod),
            $this->capturingLogger(),
            $this->userRepositoryFailingOnInsert($failure),
            $this->createMock(ExternalIdentityRepositoryInterface::class),
            $this->createMock(UserGroupLookupInterface::class),
            $this->createMock(UserGroupMemberRepositoryInterface::class)
        );
        $reflection = new ReflectionClass(UserProvisioningService::class);

        $userInfo = $this->createMock(UserInfoInterface::class);
        $userInfo->method('getUsername')->willReturn('jdoe');
        $userInfo->method('getEmail')->willReturn('jdoe@example.com');
        $userInfo->method('getDisplayName')->willReturn('J Doe');
        $userInfo->method('getFullName')->willReturn('J Doe');
        $userInfo->method('getGroups')->willReturn([]);

        $method = $reflection->getMethod('createNewUser');
        $method->setAccessible(true);
        $result = $method->invoke($service, $userInfo, 'corporate-ldap', $authMethod);

        $this->assertNull($result, 'a failed insert must not report a user id');
    }

    /**
     * The permission template lookup and the username check both have to succeed, or
     * their own catch blocks swallow the failure before the insert is reached.
     */
    private function userRepositoryFailingOnInsert(PDOException $failure): UserRepositoryInterface
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('templateGrantsUberuser')->willReturn(false);
        $repository->method('findPermissionTemplateIdByName')->willReturn(1);
        $repository->method('getUserByUsername')->willReturn(null);
        $repository->method('createProvisionedUser')->willThrowException($failure);

        return $repository;
    }

    private function configurationWithDefaultTemplate(string $authMethod): ConfigurationInterface
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => match (true) {
                $group !== $authMethod => $default,
                $key === 'default_permission_template' => 'Administrator',
                default => $default,
            }
        );

        return $config;
    }

    private function capturingLogger(): Logger
    {
        $logger = $this->createMock(Logger::class);
        $logger->method('log')->willReturnCallback(
            function (string $level, string $message, array $context): void {
                if ($level === 'error') {
                    $this->loggedErrors[] = [$message, $context];
                }
            }
        );

        return $logger;
    }
}
