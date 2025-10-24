<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace unit\Application\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\LdapAuthenticator;
use Poweradmin\Domain\Service\UserContextService;
use ReflectionClass;
use ReflectionMethod;

class LdapAuthenticatorCacheTest extends TestCase
{
    private LdapAuthenticator $authenticator;
    private ReflectionClass $reflection;
    private UserContextService $userContextService;

    protected function setUp(): void
    {
        // Mock dependencies without full construction
        $this->reflection = new ReflectionClass(LdapAuthenticator::class);
        $this->authenticator = $this->reflection->newInstanceWithoutConstructor();
        $this->userContextService = new UserContextService();

        // Mock logger to avoid initialization errors
        $mockLogger = $this->createMock(\Poweradmin\Infrastructure\Logger\Logger::class);
        $loggerProperty = $this->reflection->getParentClass()->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($this->authenticator, $mockLogger);

        // Clear session data before each test
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        // Clean up session after each test
        $_SESSION = [];
    }

    /**
     * Test cache validation when caching is disabled (timeout = 0)
     */
    public function testCacheValidationWhenCachingDisabled(): void
    {
        // Set up mock configuration with cache disabled
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturnCallback(function ($section, $key, $default) {
                if ($section === 'ldap' && $key === 'session_cache_timeout') {
                    return 0; // Caching disabled
                }
                return $default;
            });

        // Inject config manager via reflection
        $configProperty = $this->reflection->getProperty('configManager');
        $configProperty->setAccessible(true);
        $configProperty->setValue($this->authenticator, $configManager);

        // Inject UserContextService
        $userContextProperty = $this->reflection->getProperty('userContextService');
        $userContextProperty->setAccessible(true);
        $userContextProperty->setValue($this->authenticator, $this->userContextService);

        // Call private method via reflection
        $method = $this->getPrivateMethod('isCachedAuthenticationValid');
        $result = $method->invoke($this->authenticator);

        $this->assertFalse($result, 'Cache should be invalid when caching is disabled');
    }

    /**
     * Test cache validation when user is not authenticated
     */
    public function testCacheValidationWhenUserNotAuthenticated(): void
    {
        // Set up mock configuration
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturn(300); // 5 minutes timeout

        // Inject config manager
        $configProperty = $this->reflection->getProperty('configManager');
        $configProperty->setAccessible(true);
        $configProperty->setValue($this->authenticator, $configManager);

        // Inject UserContextService
        $userContextProperty = $this->reflection->getProperty('userContextService');
        $userContextProperty->setAccessible(true);
        $userContextProperty->setValue($this->authenticator, $this->userContextService);

        // Don't set userid or authenticated - user is not authenticated
        $method = $this->getPrivateMethod('isCachedAuthenticationValid');
        $result = $method->invoke($this->authenticator);

        $this->assertFalse($result, 'Cache should be invalid when user is not authenticated');
    }

    /**
     * Test cache validation when timestamp is missing
     */
    public function testCacheValidationWhenTimestampMissing(): void
    {
        // Set up mock configuration
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturn(300);

        // Inject dependencies
        $configProperty = $this->reflection->getProperty('configManager');
        $configProperty->setAccessible(true);
        $configProperty->setValue($this->authenticator, $configManager);

        $userContextProperty = $this->reflection->getProperty('userContextService');
        $userContextProperty->setAccessible(true);
        $userContextProperty->setValue($this->authenticator, $this->userContextService);

        // Set user as authenticated but no timestamp
        $_SESSION['userid'] = 1;
        $_SESSION['authenticated'] = true;

        $method = $this->getPrivateMethod('isCachedAuthenticationValid');
        $result = $method->invoke($this->authenticator);

        $this->assertFalse($result, 'Cache should be invalid when timestamp is missing');
    }

    /**
     * Test cache validation when cache has expired
     */
    public function testCacheValidationWhenCacheExpired(): void
    {
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturn(300); // 5 minutes timeout

        // Inject dependencies
        $configProperty = $this->reflection->getProperty('configManager');
        $configProperty->setAccessible(true);
        $configProperty->setValue($this->authenticator, $configManager);

        $userContextProperty = $this->reflection->getProperty('userContextService');
        $userContextProperty->setAccessible(true);
        $userContextProperty->setValue($this->authenticator, $this->userContextService);

        // Set expired timestamp (10 minutes ago)
        $_SESSION['userid'] = 1;
        $_SESSION['authenticated'] = true;
        $_SESSION['ldap_auth_timestamp'] = time() - 600;
        $_SESSION['ldap_auth_ip'] = '192.168.1.1';

        $method = $this->getPrivateMethod('isCachedAuthenticationValid');
        $result = $method->invoke($this->authenticator);

        $this->assertFalse($result, 'Cache should be invalid when expired');
    }

    /**
     * Test cache validation when cache is valid
     */
    public function testCacheValidationWhenCacheValid(): void
    {
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturn(300); // 5 minutes timeout

        // Inject dependencies
        $configProperty = $this->reflection->getProperty('configManager');
        $configProperty->setAccessible(true);
        $configProperty->setValue($this->authenticator, $configManager);

        $userContextProperty = $this->reflection->getProperty('userContextService');
        $userContextProperty->setAccessible(true);
        $userContextProperty->setValue($this->authenticator, $this->userContextService);

        // Mock server params with matching IP
        $serverParamsProperty = $this->reflection->getProperty('serverParams');
        $serverParamsProperty->setAccessible(true);
        $serverParamsProperty->setValue($this->authenticator, ['REMOTE_ADDR' => '192.168.1.1']);

        // Set valid cache (2 minutes ago)
        $_SESSION['userid'] = 1;
        $_SESSION['authenticated'] = true;
        $_SESSION['userlogin'] = 'testuser';
        $_SESSION['ldap_auth_timestamp'] = time() - 120;
        $_SESSION['ldap_auth_ip'] = '192.168.1.1';
        $_SESSION['ldap_auth_username'] = 'testuser';

        $method = $this->getPrivateMethod('isCachedAuthenticationValid');
        $result = $method->invoke($this->authenticator);

        $this->assertTrue($result, 'Cache should be valid when within timeout and IP matches');
    }

    /**
     * Test cache validation when IP address changes
     */
    public function testCacheValidationWhenIpAddressChanges(): void
    {
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturn(300);

        // Inject dependencies
        $configProperty = $this->reflection->getProperty('configManager');
        $configProperty->setAccessible(true);
        $configProperty->setValue($this->authenticator, $configManager);

        $userContextProperty = $this->reflection->getProperty('userContextService');
        $userContextProperty->setAccessible(true);
        $userContextProperty->setValue($this->authenticator, $this->userContextService);

        // Mock server params with different IP
        $serverParamsProperty = $this->reflection->getProperty('serverParams');
        $serverParamsProperty->setAccessible(true);
        $serverParamsProperty->setValue($this->authenticator, ['REMOTE_ADDR' => '192.168.1.2']);

        // Set cache with different IP
        $_SESSION['userid'] = 1;
        $_SESSION['authenticated'] = true;
        $_SESSION['userlogin'] = 'testuser';
        $_SESSION['ldap_auth_timestamp'] = time() - 120;
        $_SESSION['ldap_auth_ip'] = '192.168.1.1'; // Different IP
        $_SESSION['ldap_auth_username'] = 'testuser';

        $method = $this->getPrivateMethod('isCachedAuthenticationValid');
        $result = $method->invoke($this->authenticator);

        $this->assertFalse($result, 'Cache should be invalid when IP address changes');
    }

    /**
     * Test cache validation when username changes (account switching)
     */
    public function testCacheValidationWhenUsernameChanges(): void
    {
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturn(300);

        // Inject dependencies
        $configProperty = $this->reflection->getProperty('configManager');
        $configProperty->setAccessible(true);
        $configProperty->setValue($this->authenticator, $configManager);

        $userContextProperty = $this->reflection->getProperty('userContextService');
        $userContextProperty->setAccessible(true);
        $userContextProperty->setValue($this->authenticator, $this->userContextService);

        // Mock server params
        $serverParamsProperty = $this->reflection->getProperty('serverParams');
        $serverParamsProperty->setAccessible(true);
        $serverParamsProperty->setValue($this->authenticator, ['REMOTE_ADDR' => '192.168.1.1']);

        // Set cache with user A, but current session has user B (account switching scenario)
        $_SESSION['userid'] = 1;
        $_SESSION['authenticated'] = true;
        $_SESSION['userlogin'] = 'userB'; // Current login attempt
        $_SESSION['ldap_auth_timestamp'] = time() - 120;
        $_SESSION['ldap_auth_ip'] = '192.168.1.1';
        $_SESSION['ldap_auth_username'] = 'userA'; // Cached username

        $method = $this->getPrivateMethod('isCachedAuthenticationValid');
        $result = $method->invoke($this->authenticator);

        $this->assertFalse($result, 'Cache should be invalid when username changes (account switching)');
    }

    /**
     * Test cache validation rejects when authenticated flag is false (MFA bypass prevention)
     */
    public function testCacheValidationRejectsWhenAuthenticatedIsFalse(): void
    {
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturn(300);

        // Inject dependencies
        $configProperty = $this->reflection->getProperty('configManager');
        $configProperty->setAccessible(true);
        $configProperty->setValue($this->authenticator, $configManager);

        $userContextProperty = $this->reflection->getProperty('userContextService');
        $userContextProperty->setAccessible(true);
        $userContextProperty->setValue($this->authenticator, $this->userContextService);

        // Mock server params
        $serverParamsProperty = $this->reflection->getProperty('serverParams');
        $serverParamsProperty->setAccessible(true);
        $serverParamsProperty->setValue($this->authenticator, ['REMOTE_ADDR' => '192.168.1.1']);

        // Simulate MFA pending state: userid set but authenticated=false
        // This happens when MfaSessionManager::setMfaRequired() is called
        $_SESSION['userid'] = 1;
        $_SESSION['authenticated'] = false;  // MFA pending!
        $_SESSION['userlogin'] = 'testuser';
        $_SESSION['ldap_auth_timestamp'] = time() - 120;
        $_SESSION['ldap_auth_ip'] = '192.168.1.1';
        $_SESSION['ldap_auth_username'] = 'testuser';

        $method = $this->getPrivateMethod('isCachedAuthenticationValid');
        $result = $method->invoke($this->authenticator);

        $this->assertFalse($result, 'Cache should be invalid when authenticated=false (MFA pending)');
    }

    /**
     * Test cache validation rejects when authenticated flag is null
     */
    public function testCacheValidationRejectsWhenAuthenticatedIsNull(): void
    {
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturn(300);

        // Inject dependencies
        $configProperty = $this->reflection->getProperty('configManager');
        $configProperty->setAccessible(true);
        $configProperty->setValue($this->authenticator, $configManager);

        $userContextProperty = $this->reflection->getProperty('userContextService');
        $userContextProperty->setAccessible(true);
        $userContextProperty->setValue($this->authenticator, $this->userContextService);

        // Mock server params
        $serverParamsProperty = $this->reflection->getProperty('serverParams');
        $serverParamsProperty->setAccessible(true);
        $serverParamsProperty->setValue($this->authenticator, ['REMOTE_ADDR' => '192.168.1.1']);

        // authenticated not set at all (edge case)
        $_SESSION['userid'] = 1;
        // $_SESSION['authenticated'] not set
        $_SESSION['userlogin'] = 'testuser';
        $_SESSION['ldap_auth_timestamp'] = time() - 120;
        $_SESSION['ldap_auth_ip'] = '192.168.1.1';
        $_SESSION['ldap_auth_username'] = 'testuser';

        $method = $this->getPrivateMethod('isCachedAuthenticationValid');
        $result = $method->invoke($this->authenticator);

        $this->assertFalse($result, 'Cache should be invalid when authenticated is not set');
    }

    /**
     * Test cache update
     */
    public function testUpdateAuthenticationCache(): void
    {
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturn(300);

        // Inject dependencies
        $configProperty = $this->reflection->getProperty('configManager');
        $configProperty->setAccessible(true);
        $configProperty->setValue($this->authenticator, $configManager);

        $userContextProperty = $this->reflection->getProperty('userContextService');
        $userContextProperty->setAccessible(true);
        $userContextProperty->setValue($this->authenticator, $this->userContextService);

        $method = $this->getPrivateMethod('updateAuthenticationCache');
        $ipAddress = '192.168.1.1';

        // Set username in session
        $_SESSION['userlogin'] = 'testuser';

        // Clear cache first
        unset($_SESSION['ldap_auth_timestamp'], $_SESSION['ldap_auth_ip'], $_SESSION['ldap_auth_username']);

        $method->invoke($this->authenticator, $ipAddress);

        $this->assertArrayHasKey('ldap_auth_timestamp', $_SESSION, 'Timestamp should be set in session');
        $this->assertArrayHasKey('ldap_auth_ip', $_SESSION, 'IP address should be set in session');
        $this->assertArrayHasKey('ldap_auth_username', $_SESSION, 'Username should be set in session');

        // Assert keys exist for static analysis
        assert(isset($_SESSION['ldap_auth_timestamp']));
        assert(isset($_SESSION['ldap_auth_ip']));
        assert(isset($_SESSION['ldap_auth_username']));

        $this->assertEquals($ipAddress, $_SESSION['ldap_auth_ip'], 'IP address should match');
        $this->assertEquals('testuser', $_SESSION['ldap_auth_username'], 'Username should match');
        $this->assertGreaterThan(time() - 5, $_SESSION['ldap_auth_timestamp'], 'Timestamp should be recent');
    }

    /**
     * Test cache invalidation
     */
    public function testInvalidateAuthenticationCache(): void
    {
        // Set cache data
        $_SESSION['ldap_auth_timestamp'] = time();
        $_SESSION['ldap_auth_ip'] = '192.168.1.1';
        $_SESSION['ldap_auth_username'] = 'testuser';

        // Inject UserContextService
        $userContextProperty = $this->reflection->getProperty('userContextService');
        $userContextProperty->setAccessible(true);
        $userContextProperty->setValue($this->authenticator, $this->userContextService);

        // Call public method
        $this->authenticator->invalidateAuthenticationCache();

        // Cast to array to satisfy static analyzers (keys may or may not exist after invalidation)
        /** @var array<string, mixed> $session */
        $session = $_SESSION;
        $this->assertArrayNotHasKey('ldap_auth_timestamp', $session, 'Timestamp should be cleared');
        $this->assertArrayNotHasKey('ldap_auth_ip', $session, 'IP address should be cleared');
        $this->assertArrayNotHasKey('ldap_auth_username', $session, 'Username should be cleared');
    }

    /**
     * Test that validateUserActiveStatus returns true for active LDAP user
     */
    public function testValidateUserActiveStatusReturnsTrueForActiveUser(): void
    {
        // Mock PDO and statement
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->expects($this->once())
            ->method('execute')
            ->with(['username' => 'testuser'])
            ->willReturn(true);
        $mockStmt->expects($this->once())
            ->method('fetch')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturn(['id' => 1, 'fullname' => 'Test User']);

        $mockDb = $this->createMock(\Poweradmin\Infrastructure\Database\PDOCommon::class);
        $mockDb->expects($this->once())
            ->method('prepare')
            ->with("SELECT id, fullname FROM users WHERE username = :username AND active = 1 AND use_ldap = 1")
            ->willReturn($mockStmt);

        // Inject mock database
        $dbProperty = $this->reflection->getProperty('db');
        $dbProperty->setAccessible(true);
        $dbProperty->setValue($this->authenticator, $mockDb);

        // Call private method
        $method = $this->getPrivateMethod('validateUserActiveStatus');
        $result = $method->invoke($this->authenticator, 'testuser');

        $this->assertTrue($result, 'Should return true for active LDAP user');
    }

    /**
     * Test that validateUserActiveStatus returns false for inactive user
     */
    public function testValidateUserActiveStatusReturnsFalseForInactiveUser(): void
    {
        // Mock PDO and statement - returns no rows (user inactive or not found)
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->expects($this->once())
            ->method('execute')
            ->with(['username' => 'inactiveuser'])
            ->willReturn(true);
        $mockStmt->expects($this->once())
            ->method('fetch')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturn(false); // No user found

        $mockDb = $this->createMock(\Poweradmin\Infrastructure\Database\PDOCommon::class);
        $mockDb->expects($this->once())
            ->method('prepare')
            ->willReturn($mockStmt);

        // Inject mock database
        $dbProperty = $this->reflection->getProperty('db');
        $dbProperty->setAccessible(true);
        $dbProperty->setValue($this->authenticator, $mockDb);

        // Call private method
        $method = $this->getPrivateMethod('validateUserActiveStatus');
        $result = $method->invoke($this->authenticator, 'inactiveuser');

        $this->assertFalse($result, 'Should return false for inactive or non-existent user');
    }

    /**
     * Test that validateUserActiveStatus returns false for user with use_ldap=0
     */
    public function testValidateUserActiveStatusReturnsFalseForNonLdapUser(): void
    {
        // Mock PDO and statement - returns no rows (use_ldap=0 filtered out by query)
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->expects($this->once())
            ->method('execute')
            ->with(['username' => 'localuser'])
            ->willReturn(true);
        $mockStmt->expects($this->once())
            ->method('fetch')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturn(false); // No user found (filtered by use_ldap=1)

        $mockDb = $this->createMock(\Poweradmin\Infrastructure\Database\PDOCommon::class);
        $mockDb->expects($this->once())
            ->method('prepare')
            ->willReturn($mockStmt);

        // Inject mock database
        $dbProperty = $this->reflection->getProperty('db');
        $dbProperty->setAccessible(true);
        $dbProperty->setValue($this->authenticator, $mockDb);

        // Call private method
        $method = $this->getPrivateMethod('validateUserActiveStatus');
        $result = $method->invoke($this->authenticator, 'localuser');

        $this->assertFalse($result, 'Should return false for user with use_ldap=0');
    }

    /**
     * Helper method to access private methods via reflection
     */
    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }
}
