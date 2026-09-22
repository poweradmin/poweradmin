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
use Poweradmin\Application\Service\Zone\ChangeApprovalContext;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\Auth\CsrfTokenService;
use Poweradmin\Application\Service\Zone\ZoneSortingService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Service\SqlDnsBackendProvider;
use Poweradmin\Infrastructure\Utility\ReverseZoneSorting;
use Psr\Log\NullLogger;

/**
 * Shared fixture for controllers built through the ControllerEnvironment seam:
 * an in-memory configuration, a request assembled from post()/query(), a page
 * output that records instead of rendering, and a stub service factory that
 * every create*() accessor on BaseController routes through.
 *
 * The logged-in user is a plain $_SESSION entry, so UserContextService,
 * MessageService and ZoneSortingService all work against real (in-memory) state;
 * the session is the one superglobal the seam still swaps, since those services
 * read it directly.
 */
abstract class SeamControllerTestCase extends TestCase
{
    protected const USER_ID = 4;
    protected const USERNAME = 'tester';

    /** @var ControllerServiceFactory&MockObject */
    protected ControllerServiceFactory $factory;

    protected MessageService $messageService;

    /** The configuration every controller of the test reads; configure() sets its contents */
    protected SeamConfiguration $config;

    /** What the controller built last rendered; environment() starts a fresh one per controller */
    protected RecordingPageOutput $output;

    /** @var list<RecordingPageOutput> Every recorder of the test, oldest first */
    private array $outputs = [];

    /** @var array<string, mixed> */
    protected array $queryParams = [];

    /** @var array<string, mixed> */
    protected array $postParams = [];

    private string $method = 'GET';

    private array $sessionBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = [
            SessionKeys::USERID => self::USER_ID,
            SessionKeys::USERLOGIN => self::USERNAME,
        ];

        $this->factory = $this->createMock(ControllerServiceFactory::class);
        $this->messageService = new MessageService();
        $this->config = new SeamConfiguration();
        $this->configure();
        $this->output = $this->outputs[] = new RecordingPageOutput();
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;

        parent::tearDown();
    }

    /**
     * Sets the test's configuration, merging $overrides section by section over
     * a minimal SQL-backend baseline.
     *
     * @param array<string, array<string, mixed>> $overrides
     */
    protected function configure(array $overrides = []): ConfigurationInterface
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

        $this->config->replace($settings);

        return $this->config;
    }

    protected function environment(ConfigurationInterface $config): ControllerEnvironment
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

        // One recorder per controller, so a test running two of them reads each one's page
        $this->output = $this->outputs[] = new RecordingPageOutput();

        return new ControllerEnvironment(
            $config,
            $db,
            new NullLogger(),
            $registry,
            $this->factory,
            $this->request(),
            $csrf,
            $this->messageService,
            new UserContextService(),
            $this->output
        );
    }

    /**
     * The template variables of the first page rendered, as the controller built them.
     *
     * @return array<string, mixed>
     */
    protected function renderedParams(): array
    {
        return $this->output->renderedParams();
    }

    protected function renderedTemplate(): ?string
    {
        return $this->output->renderedTemplate();
    }

    /**
     * The pending request as the controller will see it: the method and the
     * parameters given to post() and query() so far.
     */
    protected function request(): HttpRequest
    {
        return new HttpRequest($this->queryParams, $this->postParams, ['REQUEST_METHOD' => $this->method]);
    }

    /**
     * The real provider for the configured dns.backend, so capability answers
     * are the shipped ones; its data methods are never reached over a PDO mock.
     */
    private function backendProvider(ConfigurationInterface $config, PDO $db): DnsBackendProviderInterface
    {
        if ($config->get('dns', 'backend') === 'api') {
            return new ApiDnsBackendProvider($this->createMock(PowerdnsApiClient::class), $db, $config, new NullLogger());
        }

        return new SqlDnsBackendProvider($db, $config, new NullLogger());
    }

    /**
     * Switches the pending request to POST. The environment's request is built
     * when the controller is, so call this before constructing it.
     *
     * @param array<string, mixed> $fields
     */
    protected function post(array $fields): void
    {
        $this->method = 'POST';
        $this->postParams = $fields + ['_token' => 'tok'];
    }

    /** @param array<string, mixed> $params */
    protected function query(array $params): void
    {
        $this->queryParams = $params;
    }

    /**
     * The request data the router would hand the controller: query and post
     * parameters merged, post winning.
     *
     * @return array<string, mixed>
     */
    protected function requestData(): array
    {
        return array_merge($this->queryParams, $this->postParams);
    }

    /**
     * The flashed messages for a page, as [type, content] pairs: the ones the
     * rendered pages already showed, then the ones still waiting in the session.
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function messagesFor(string $script): array
    {
        $shown = array_merge(...array_map(static fn(RecordingPageOutput $output): array => $output->messages[$script] ?? [], $this->outputs));

        return array_map(
            static fn(array $message): array => [$message['type'], $message['content']],
            array_merge($shown, $this->messageService->getMessages($script) ?? [])
        );
    }
}
