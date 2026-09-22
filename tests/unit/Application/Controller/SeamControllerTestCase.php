<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\Request as HttpRequest;
use Poweradmin\Application\Module\ModuleRegistry;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Application\Service\ChangeApprovalContext;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\ZoneSortingService;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;
use Poweradmin\Infrastructure\Utility\ReverseZoneSorting;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;

/**
 * Shared fixture for controllers built through the ControllerEnvironment seam:
 * a runtime configuration, superglobals isolated per test, and a stub service
 * factory that every create*() accessor on BaseController routes through.
 *
 * The logged-in user is a plain $_SESSION entry, so UserContextService,
 * MessageService and ZoneSortingService all work against real (in-memory) state.
 */
abstract class SeamControllerTestCase extends TestCase
{
    protected const USER_ID = 4;
    protected const USERNAME = 'tester';

    /** @var ControllerServiceFactory&MockObject */
    protected ControllerServiceFactory $factory;

    protected MessageService $messageService;

    private array $configBackup = [];
    private bool $configInitializedBackup = false;
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];
    private array $sessionBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        [$settings, $initialized] = self::configProperties();
        $config = ConfigurationManager::getInstance();
        $this->configBackup = $settings->getValue($config);
        $this->configInitializedBackup = $initialized->getValue($config);

        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->sessionBackup = $_SESSION ?? [];

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        $_POST = [];
        $_SESSION = [
            SessionKeys::USERID => self::USER_ID,
            SessionKeys::USERLOGIN => self::USERNAME,
        ];

        $this->factory = $this->createMock(ControllerServiceFactory::class);
        $this->messageService = new MessageService();
    }

    protected function tearDown(): void
    {
        [$settings, $initialized] = self::configProperties();
        $config = ConfigurationManager::getInstance();
        $settings->setValue($config, $this->configBackup);
        $initialized->setValue($config, $this->configInitializedBackup);

        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_SESSION = $this->sessionBackup;

        parent::tearDown();
    }

    /** @return array{0: ReflectionProperty, 1: ReflectionProperty} */
    private static function configProperties(): array
    {
        $reflection = new ReflectionClass(ConfigurationManager::class);

        return [$reflection->getProperty('settings'), $reflection->getProperty('initialized')];
    }

    /**
     * Installs a runtime configuration, merging $overrides section by section
     * over a minimal SQL-backend baseline.
     *
     * @param array<string, array<string, mixed>> $overrides
     */
    protected function configure(array $overrides = []): ConfigurationManager
    {
        $settings = [
            'database' => ['type' => 'sqlite'],
            'security' => ['global_token_validation' => true],
            'interface' => ['rows_per_page' => 10],
            'dns' => ['backend' => 'sql'],
            'approval' => ['enabled' => false, 'require_review_for_all' => false],
            'dnssec' => ['enabled' => false],
        ];
        foreach ($overrides as $section => $values) {
            $settings[$section] = array_merge($settings[$section] ?? [], $values);
        }

        [$settingsProperty, $initialized] = self::configProperties();
        $config = ConfigurationManager::getInstance();
        $settingsProperty->setValue($config, $settings);
        $initialized->setValue($config, true);

        return $config;
    }

    protected function environment(ConfigurationManager $config): ControllerEnvironment
    {
        $csrf = $this->createMock(CsrfTokenService::class);
        $csrf->method('validateToken')->willReturn(true);

        // The real context over the stubbed factory, so approval answers follow
        // whatever permission and repository doubles a test plants
        $this->factory->method('changeApprovalContext')->willReturn(new ChangeApprovalContext(
            $config,
            fn() => $this->factory->permissionService(),
            fn() => $this->factory->zoneRepository(),
            fn() => $this->factory->zoneChangeRequestRepository()
        ));

        $db = $this->createMock(PDO::class);
        // Resolved per call: a test may reconfigure dns.backend between two controllers
        $this->factory->method('dnsBackendProvider')->willReturnCallback(fn() => $this->backendProvider($config, $db));
        // The real mode resolver, so the ownership flags a page renders follow dns.zone_ownership_mode
        $this->factory->method('zoneOwnershipModeService')->willReturn(new ZoneOwnershipModeService($config));
        // The real sorter over the in-memory session, so list pages sort as shipped
        $this->factory->method('zoneSortingService')->willReturnCallback(
            static fn(UserContextService $userContext): ZoneSortingService => new ZoneSortingService(new ReverseZoneSorting(), $userContext)
        );
        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        return new ControllerEnvironment(
            $config,
            $db,
            new NullLogger(),
            $registry,
            $this->factory,
            new HttpRequest(),
            $csrf,
            $this->messageService,
            new UserContextService()
        );
    }

    /**
     * The real provider for the configured dns.backend, so capability answers
     * are the shipped ones; its data methods are never reached over a PDO mock.
     */
    private function backendProvider(ConfigurationManager $config, PDO $db): DnsBackendProviderInterface
    {
        if ($config->get('dns', 'backend') === 'api') {
            return new ApiDnsBackendProvider($this->createMock(PowerdnsApiClient::class), $db, $config, new NullLogger());
        }

        return new SqlDnsBackendProvider($db, $config, new NullLogger());
    }

    /**
     * Switches the pending request to POST. The environment's HttpRequest is
     * built afterwards, so $_POST must be in place before the controller.
     *
     * @param array<string, mixed> $fields
     */
    protected function post(array $fields): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $fields + ['_token' => 'tok'];
    }

    /** @param array<string, mixed> $params */
    protected function query(array $params): void
    {
        $_GET = $params;
    }

    /**
     * The flashed messages for a page, as [type, content] pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function messagesFor(string $script): array
    {
        return array_map(
            static fn(array $message): array => [$message['type'], $message['content']],
            $this->messageService->getMessages($script) ?? []
        );
    }
}
