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

namespace Poweradmin\Tests\Unit\Module\DnsWizard\Controller\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Service\ChangeApprovalContext;
use Poweradmin\Application\Service\RecordAddService;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\DomainRecordCreator;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Poweradmin\Module\DnsWizard\Controller\Api\DnsWizardApiController;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Characterizes the wizard API's create action: the body it accepts, the gates
 * it applies and the status and message of every refusal.
 */
#[CoversClass(DnsWizardApiController::class)]
class DnsWizardApiControllerCreateTest extends SeamControllerTestCase
{
    private const ZONE_ID = 12;

    private string $editLevel = 'all';
    private bool $ownsZone = true;
    private string $zoneType = 'MASTER';
    private ?string $zoneName = 'example.com';
    private string $requestLevel = 'none';

    /** @var list<RecordWriteResult> */
    private array $writeResults = [];

    /** @var list<array<int, mixed>> */
    private array $writes = [];

    /** @var RecordManagerService&MockObject */
    private RecordManagerService $records;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);
        $permissions->method('getEditPermissionLevel')->willReturnCallback(fn(): string => $this->editLevel);
        $permissions->method('getEditPermissionLevelForZone')->willReturnCallback(fn(): string => $this->editLevel);
        $permissions->method('getChangeRequestPermissionLevelForZone')->willReturnCallback(fn(): string => $this->requestLevel);
        $permissions->method('userOwnsZone')->willReturnCallback(fn(): bool => $this->ownsZone);
        $permissions->method('canEditZoneContent')->willReturnCallback(
            fn(): bool => $this->zoneType === 'MASTER' && ($this->editLevel === 'all' || ($this->editLevel !== 'none' && $this->ownsZone))
        );
        $permissions->method('canEditZoneRecord')->willReturn(true);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('zoneIdExists')->willReturnCallback(fn(): bool => $this->zoneName !== null);
        $domains->method('getDomainType')->willReturnCallback(fn(): string => $this->zoneType);
        $domains->method('getDomainNameById')->willReturnCallback(fn(): ?string => $this->zoneName);

        $this->records = $this->createMock(RecordManagerService::class);
        $this->records->method('createRecord')->willReturnCallback(function (...$args): RecordWriteResult {
            $this->writes[] = $args;
            return array_shift($this->writeResults) ?? RecordWriteResult::ok(1);
        });

        $ttl = $this->createMock(ReverseTtlResolver::class);
        $ttl->method('resolveTtlForType')->willReturn(3600);
        $ttl->method('resolvePtrTtl')->willReturnArgument(0);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($domains);
        $this->factory->method('recordManagerService')->willReturn($this->records);
        $this->factory->method('reverseTtlResolver')->willReturn($ttl);

        // The real add flow over the mocked collaborators, so the gates and the
        // write are exercised as production wires them
        $this->factory->method('recordAddService')->willReturn(new RecordAddService(
            $this->records,
            $this->createMock(ReverseRecordCreator::class),
            $this->createMock(DomainRecordCreator::class),
            $ttl,
            $permissions,
            $domains,
            new ChangeApprovalContext(
                $this->config,
                fn(): PermissionService => $permissions,
                fn(): ZoneRepositoryInterface => $this->createMock(ZoneRepositoryInterface::class),
                fn(): ZoneChangeRequestRepositoryInterface => $this->createMock(ZoneChangeRequestRepositoryInterface::class)
            )
        ));
    }

    /**
     * Runs the create action against a JSON body, bypassing the API
     * constructor's login and API-enabled checks.
     *
     * @param array<string, mixed>|string $body
     * @param array<string, array<string, mixed>> $config
     */
    private function create(array|string $body, array $config = []): JsonResponse
    {
        $controller = (new \ReflectionClass(DnsWizardApiController::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod(BaseController::class, '__construct'))
            ->invoke($controller, [], true, $this->environment($this->configure($config)));

        $content = is_string($body) ? $body : json_encode($body);
        (new ReflectionProperty($controller, 'request'))
            ->setValue($controller, Request::create('/api/internal/dns-wizard?action=create', 'POST', [], [], [], [], $content));

        return (new ReflectionMethod($controller, 'createRecord'))->invoke($controller);
    }

    /** @return array<string, mixed> */
    private function decoded(JsonResponse $response): array
    {
        return json_decode((string)$response->getContent(), true);
    }

    /** @return array<string, mixed> */
    private static function validBody(): array
    {
        return ['zone_id' => self::ZONE_ID, 'name' => '@', 'type' => 'TXT', 'content' => '"v=spf1 -all"'];
    }

    // ----------------------------------------------------------- body checks

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function incompleteBodyProvider(): array
    {
        return [
            'no zone' => [['name' => '@', 'type' => 'TXT', 'content' => 'x'], 'Missing zone_id'],
            'zero zone' => [['zone_id' => 0, 'name' => '@', 'type' => 'TXT', 'content' => 'x'], 'Missing zone_id'],
            'no name' => [['zone_id' => 1, 'type' => 'TXT', 'content' => 'x'], 'Missing record name'],
            'no type' => [['zone_id' => 1, 'name' => '@', 'content' => 'x'], 'Missing record type'],
            'no content' => [['zone_id' => 1, 'name' => '@', 'type' => 'TXT'], 'Missing record content'],
        ];
    }

    #[DataProvider('incompleteBodyProvider')]
    public function testAnIncompleteBodyIsRefusedBeforeAnyLookup(array $body, string $message): void
    {
        $this->records->expects($this->never())->method('createRecord');

        $response = $this->create($body);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame($message, $this->decoded($response)['message']);
    }

    public function testAnEmptyNameIsAcceptedAsTheApex(): void
    {
        $response = $this->create(['zone_id' => self::ZONE_ID, 'name' => '', 'type' => 'TXT', 'content' => 'x']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('example.com', $this->writes[0][1]);
    }

    // ---------------------------------------------------------------- gates

    public function testAnUnknownZoneIs404(): void
    {
        $this->zoneName = null;

        $response = $this->create(self::validBody());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Zone not found', $this->decoded($response)['message']);
        $this->assertCount(0, $this->writes);
    }

    /** @return array<string, array{0: string, 1: string, 2: bool, 3: bool}> */
    public static function editGateProvider(): array
    {
        return [
            'read-only secondary zone' => ['SLAVE', 'all', true, false],
            'no edit permission' => ['MASTER', 'none', true, false],
            'own scope without ownership' => ['MASTER', 'own', false, false],
            'own scope with ownership' => ['MASTER', 'own', true, true],
            'unrestricted edit' => ['MASTER', 'all', false, true],
        ];
    }

    #[DataProvider('editGateProvider')]
    public function testTheZoneMustBeWritableByThisUser(string $zoneType, string $editLevel, bool $owns, bool $allowed): void
    {
        $this->zoneType = $zoneType;
        $this->editLevel = $editLevel;
        $this->ownsZone = $owns;

        $response = $this->create(self::validBody());

        if ($allowed) {
            $this->assertSame(200, $response->getStatusCode());
            return;
        }

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to add records to this zone', $this->decoded($response)['message']);
        $this->assertCount(0, $this->writes);
    }

    public function testAZoneNeedingReviewIsRefusedLikeTheForm(): void
    {
        $this->requestLevel = 'all';

        $response = $this->create(self::validBody(), ['approval' => ['enabled' => true, 'require_review_for_all' => true]]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'This zone requires approval for changes; use the zone editor to submit a change request.',
            $this->decoded($response)['message']
        );
        $this->assertCount(0, $this->writes);
    }

    // ---------------------------------------------------------------- write

    public function testASuccessfulCreateWritesTheNormalisedRecordAndFlashesTheZoneEditor(): void
    {
        $response = $this->create(self::validBody() + ['ttl' => '', 'priority' => '', 'comment' => 'by wizard']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => true, 'message' => 'Record created successfully'], $this->decoded($response)['data']);
        $this->assertSame([['success', 'The record was successfully added.']], $this->messagesFor('edit'));
        $this->assertSame(
            [self::ZONE_ID, 'example.com', 'TXT', '"v=spf1 -all"', 3600, 0, 'by wizard', self::USERNAME],
            array_slice($this->writes[0], 0, 8)
        );
    }

    public function testASubmittedTtlAndPriorityAreUsedAsGiven(): void
    {
        $this->create(['name' => 'mail', 'ttl' => '300', 'priority' => '10'] + self::validBody());

        $this->assertSame('mail.example.com', $this->writes[0][1]);
        $this->assertSame(300, $this->writes[0][4]);
        $this->assertSame(10, $this->writes[0][5]);
    }

    public function testARefusedWriteRelaysItsMessageAndStatus(): void
    {
        $this->writeResults = [RecordWriteResult::failure('There is already a record with this name and content.', Refusal::CONFLICT)];

        $response = $this->create(self::validBody());

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('There is already a record with this name and content.', $this->decoded($response)['message']);
        $this->assertSame([], $this->messagesFor('edit'));
    }
}
