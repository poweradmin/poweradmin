<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\Domain\ValueObject\UserInfoInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
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
        $reflection = new ReflectionClass(UserProvisioningService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $this->setProperty($reflection, $service, 'db', $this->databaseFailingOnInsert($failure));
        $this->setProperty($reflection, $service, 'configManager', $this->configurationWithDefaultTemplate($authMethod));
        $this->setProperty($reflection, $service, 'userRepository', $this->permissiveUserRepository());
        $this->setProperty($reflection, $service, 'dbType', 'mysql');
        $this->setProperty($reflection, $service, 'binaryCollation', '');

        $parent = $reflection->getParentClass();
        $this->setProperty($parent, $service, 'logger', $this->capturingLogger());
        $this->setProperty($parent, $service, 'className', 'UserProvisioningService');

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
    private function databaseFailingOnInsert(PDOException $failure): PDO
    {
        $template = $this->createMock(PDOStatement::class);
        $template->method('execute')->willReturn(true);
        $template->method('fetch')->willReturn(['id' => 1]);

        $usernameLookup = $this->createMock(PDOStatement::class);
        $usernameLookup->method('execute')->willReturn(true);
        $usernameLookup->method('fetch')->willReturn(false);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(
            function (string $sql) use ($template, $usernameLookup, $failure) {
                if (str_contains($sql, 'INSERT INTO users')) {
                    throw $failure;
                }

                return str_contains($sql, 'perm_templ') ? $template : $usernameLookup;
            }
        );

        return $db;
    }

    private function configurationWithDefaultTemplate(string $authMethod): ConfigurationManager
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => match (true) {
                $group !== $authMethod => $default,
                $key === 'default_permission_template' => 'Administrator',
                default => $default,
            }
        );

        return $config;
    }

    private function permissiveUserRepository(): DbUserRepository
    {
        $repository = $this->createMock(DbUserRepository::class);
        $repository->method('templateGrantsUberuser')->willReturn(false);

        return $repository;
    }

    private function capturingLogger(): Logger
    {
        $logger = $this->createMock(Logger::class);
        $logger->method('error')->willReturnCallback(
            function (string $message, array $context): void {
                $this->loggedErrors[] = [$message, $context];
            }
        );

        return $logger;
    }

    private function setProperty(ReflectionClass $reflection, object $target, string $name, mixed $value): void
    {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}
