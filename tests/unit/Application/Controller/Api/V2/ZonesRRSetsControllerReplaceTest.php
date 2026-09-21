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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZonesRRSetsController;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\RRSetReplaceService;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Characterization of ZonesRRSetsController::replaceRRSet - the gate order,
 * every input-validation refusal it answers with before the replace service is
 * involved, and how it words the service's outcomes. Pinned as the code behaves today.
 */
class ZonesRRSetsControllerReplaceTest extends V2ControllerTestCase
{

    private const USER_ID = 7;
    private const ZONE_ID = 42;
    private const ZONE_NAME = 'example.com';

    private ApiPermissionService&MockObject $permissions;
    private ZoneReadRepositoryInterface&MockObject $zones;
    private RecordRepositoryInterface&MockObject $records;
    private DomainRepositoryInterface&MockObject $domains;
    private RRSetReplaceService&MockObject $replacer;

    /** @var array<string, mixed>|null */
    private ?array $zoneRow = ['id' => self::ZONE_ID, 'name' => self::ZONE_NAME, 'type' => 'MASTER'];
    private ?string $zoneName = self::ZONE_NAME;
    private ApiKeyScope $scope;
    private int $zoneIdParameter = self::ZONE_ID;

    protected function setUp(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->zones = $this->createMock(ZoneReadRepositoryInterface::class);
        $this->records = $this->createMock(RecordRepositoryInterface::class);
        $this->domains = $this->createMock(DomainRepositoryInterface::class);
        $this->replacer = $this->createMock(RRSetReplaceService::class);
        $this->scope = ApiKeyScope::unrestricted();

        // The permissive defaults: every gate open, so each test only overrides
        // the single gate whose refusal it is pinning.
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('canEditZoneRecord')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
    }

    public function testANonPositiveZoneIdIsRefusedBeforeAnythingElse(): void
    {
        $this->zoneIdParameter = 0;
        $this->zones->expects($this->never())->method('getZoneById');

        $response = $this->replace(['name' => 'www', 'type' => 'A', 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Valid zone ID is required', $this->messageOf($response));
    }

    public function testAnOutOfScopeApiKeyIsRefusedBeforeTheZoneIsLookedUp(): void
    {
        $this->scope = new ApiKeyScope([999], null, false);
        $this->zones->expects($this->never())->method('getZoneById');

        $response = $this->replace(['name' => 'www', 'type' => 'A', 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden: this API key does not have access to the requested zone', $this->messageOf($response));
    }

    public function testAMissingZoneIs404(): void
    {
        $this->zoneRow = null;

        $response = $this->replace(['name' => 'www', 'type' => 'A', 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Zone not found', $this->messageOf($response));
    }

    public function testAZoneWithoutEditPermissionIs403(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(false);

        $response = $this->replace(['name' => 'www', 'type' => 'A', 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to edit this zone', $this->messageOf($response));
    }

    public function testASecondaryZoneIsRefusedAsReadOnlyRatherThanUnpermitted(): void
    {
        $this->zoneRow = ['id' => self::ZONE_ID, 'name' => self::ZONE_NAME, 'type' => 'SLAVE'];
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(false);

        $response = $this->replace(['name' => 'www', 'type' => 'A', 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Records in Secondary and Consumer zones are read-only; they replicate from a primary',
            $this->messageOf($response)
        );
    }

    public function testARequestModeCallerIsPointedAtChangeRequests(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_REQUEST);

        $response = $this->replace(['name' => 'www', 'type' => 'A', 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Changes to this zone require approval; create a change request instead', $this->messageOf($response));
    }

    public function testAScalarJsonBodyIsRejectedAsInvalidJson(): void
    {
        $response = $this->replaceRaw('"just a string"');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON in request body', $this->messageOf($response));
    }

    public function testAnEmptyBodyIsRejectedAsInvalidJson(): void
    {
        $response = $this->replaceRaw('');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid JSON in request body', $this->messageOf($response));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function missingFieldProvider(): array
    {
        return [
            'no name' => [['type' => 'A', 'records' => [['content' => '192.0.2.1']]], "Field 'name' is required"],
            'no type' => [['name' => 'www', 'records' => [['content' => '192.0.2.1']]], "Field 'type' is required"],
            'no records' => [['name' => 'www', 'type' => 'A'], "Field 'records' is required"],
            // isset() is false for null, so an explicit null reads as absent.
            'null name' => [['name' => null, 'type' => 'A', 'records' => [['content' => '1.2.3.4']]], "Field 'name' is required"],
            'null records' => [['name' => 'www', 'type' => 'A', 'records' => null], "Field 'records' is required"],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('missingFieldProvider')]
    public function testARequiredFieldThatIsAbsentOrNullIs400(array $body, string $message): void
    {
        $response = $this->replace($body);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame($message, $this->messageOf($response));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableRecordsProvider(): array
    {
        return [
            'empty array' => [[]],
            'a string' => ['192.0.2.1'],
            'an integer' => [5],
        ];
    }

    #[DataProvider('unusableRecordsProvider')]
    public function testRecordsMustBeANonEmptyArray(mixed $records): void
    {
        $response = $this->replace(['name' => 'www', 'type' => 'A', 'records' => $records]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame("Field 'records' must be a non-empty array", $this->messageOf($response));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedNameOrTypeProvider(): array
    {
        return [
            'array name' => [['name' => ['www'], 'type' => 'A', 'records' => [['content' => '192.0.2.1']]]],
            'integer type' => [['name' => 'www', 'type' => 1, 'records' => [['content' => '192.0.2.1']]]],
            'boolean name' => [['name' => true, 'type' => 'A', 'records' => [['content' => '192.0.2.1']]]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('malformedNameOrTypeProvider')]
    public function testANonStringNameOrTypeIs400(array $body): void
    {
        $response = $this->replace($body);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid field types in request body', $this->messageOf($response));
    }

    public function testAZoneRowWithoutAResolvableNameIs404(): void
    {
        $this->zoneName = null;

        $response = $this->replace(['name' => 'www', 'type' => 'A', 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Zone not found', $this->messageOf($response));
    }

    public function testANonNumericTtlIs400(): void
    {
        $response = $this->replace(['name' => 'www', 'type' => 'A', 'ttl' => 'soon', 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid field types in request body', $this->messageOf($response));
    }

    /**
     * @return array<string, array{int|string}>
     */
    public static function negativeTtlProvider(): array
    {
        return [
            'negative' => [-1],
            // Numeric strings are coerced first, so "-1" fails the same range check.
            'numeric string negative' => ['-1'],
        ];
    }

    #[DataProvider('negativeTtlProvider')]
    public function testANegativeTtlIs400(int|string $ttl): void
    {
        $response = $this->replace(['name' => 'www', 'type' => 'A', 'ttl' => $ttl, 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('TTL must not be negative', $this->messageOf($response));
    }

    public function testAZeroTtlIsAcceptedAsDoNotCache(): void
    {
        // TTLValidator allows 0, so the API must not refuse it either. Proven the
        // way the omitted-TTL case below is: the next gate is the one that answers.
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
        $this->permissions->expects($this->once())->method('canEditZoneRecord')->willReturn(false);

        $response = $this->replace(['name' => 'www', 'type' => 'A', 'ttl' => 0, 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to edit this record type', $this->messageOf($response));
    }

    public function testAnOmittedTtlFallsBackToTheResolverAndPassesTheRangeCheck(): void
    {
        // The resolver answer is what the range check sees; a positive default
        // carries the request past the TTL gate into the record-type check.
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
        $this->permissions->expects($this->once())->method('canEditZoneRecord')->willReturn(false);

        $response = $this->replace(['name' => 'www', 'type' => 'A', 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('You do not have permission to edit this record type', $this->messageOf($response));
    }

    public function testTheRecordTypeCheckSeesTheUppercasedTypeAndTheFqdn(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
        $this->permissions->expects($this->once())
            ->method('canEditZoneRecord')
            ->with(self::USER_ID, self::ZONE_ID, 'MX', 'MASTER', 'mail.example.com', self::ZONE_NAME)
            ->willReturn(false);

        $response = $this->replace(['name' => ' mail ', 'type' => 'mx', 'ttl' => 3600, 'records' => [['content' => '10 mx.example.com']]]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheApexIsAddressedWithAnAtSign(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
        $this->permissions->expects($this->once())
            ->method('canEditZoneRecord')
            ->with(self::USER_ID, self::ZONE_ID, 'TXT', 'MASTER', self::ZONE_NAME, self::ZONE_NAME)
            ->willReturn(false);

        $response = $this->replace(['name' => '@', 'type' => 'TXT', 'ttl' => 60, 'records' => [['content' => 'hello']]]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAnIdnNameIsPunycodedBeforeThePermissionCheck(): void
    {
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
        $this->permissions->expects($this->once())
            ->method('canEditZoneRecord')
            ->with(self::USER_ID, self::ZONE_ID, 'A', 'MASTER', 'xn--bcher-kva.example.com', self::ZONE_NAME)
            ->willReturn(false);

        $response = $this->replace(['name' => 'bücher', 'type' => 'A', 'ttl' => 60, 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAnEmptyNameIsAcceptedAndReadsAsTheApex(): void
    {
        // There is no blank check on 'name': isset() passes for "" and the empty
        // label is then restored to the bare zone name.
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canEditZoneContent')->willReturn(true);
        $this->permissions->method('getChangeApprovalMode')->willReturn(ChangeApprovalPolicy::MODE_DIRECT);
        $this->permissions->expects($this->once())
            ->method('canEditZoneRecord')
            ->with(self::USER_ID, self::ZONE_ID, 'A', 'MASTER', self::ZONE_NAME, self::ZONE_NAME)
            ->willReturn(false);

        $response = $this->replace(['name' => '', 'type' => 'A', 'ttl' => 60, 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testEveryErrorCarriesTheWrappedEnvelope(): void
    {
        $this->zoneRow = null;

        $body = $this->decode($this->replace(['name' => 'www', 'type' => 'A', 'records' => [['content' => '192.0.2.1']]]));

        $this->assertSame(['success', 'data', 'message'], array_keys($body));
        $this->assertFalse($body['success']);
        $this->assertNull($body['data']);
    }

    public function testRecordsAreParsedFormattedAndHandedToTheServiceWithoutContentlessEntries(): void
    {
        $this->replacer->expects($this->once())
            ->method('replace')
            ->with(self::ZONE_ID, self::ZONE_NAME, 'txt.example.com', 'TXT', 60, [
                ['content' => '"hello"', 'priority' => 5, 'disabled' => 1],
                ['content' => '"world"', 'priority' => 0, 'disabled' => 0],
            ])
            ->willReturn(['success' => true, 'message' => 'RRSet replaced successfully', 'status' => 200, 'name' => 'txt.example.com', 'records' => []]);
        $this->records->method('getRRSetRecords')->willReturn([
            ['name' => 'txt.example.com', 'type' => 'TXT', 'ttl' => 60, 'content' => '"hello"', 'prio' => 5, 'disabled' => 1],
            ['name' => 'txt.example.com', 'type' => 'TXT', 'ttl' => 60, 'content' => '"world"', 'prio' => 0, 'disabled' => 0],
        ]);

        $response = $this->replace(['name' => 'txt', 'type' => 'txt', 'ttl' => 60, 'records' => [
            ['content' => ' hello ', 'priority' => '5', 'disabled' => true],
            ['priority' => 9],
            ['content' => 'world'],
        ]]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'data' => ['rrset' => [
                'name' => 'txt',
                'type' => 'TXT',
                'ttl' => 60,
                'records' => [
                    ['content' => 'hello', 'priority' => 5, 'disabled' => true],
                    ['content' => 'world', 'priority' => 0, 'disabled' => false],
                ],
            ]],
            'message' => 'RRSet replaced successfully',
        ], $this->decode($response));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function uncoercibleRecordFieldProvider(): array
    {
        return [
            'disabled string' => [['content' => '192.0.2.1', 'disabled' => 'maybe']],
            'priority float' => [['content' => '192.0.2.1', 'priority' => 1.5]],
            'priority array' => [['content' => '192.0.2.1', 'priority' => [1]]],
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    #[DataProvider('uncoercibleRecordFieldProvider')]
    public function testAnUncoercibleDisabledOrPriorityIsRefusedBeforeTheServiceRuns(array $record): void
    {
        $this->replacer->expects($this->never())->method('replace');

        $response = $this->replace(['name' => 'www', 'type' => 'A', 'ttl' => 60, 'records' => [$record]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame("Invalid 'disabled' or 'priority' value in record", $this->messageOf($response));
    }

    /**
     * @return array<string, array{array<string, mixed>, int, string}>
     */
    public static function serviceRefusalProvider(): array
    {
        return [
            'validator' => [['success' => false, 'message' => 'Invalid IPv4 address', 'status' => 400], 400, 'Invalid IPv4 address'],
            'nothing usable' => [['success' => false, 'message' => 'No valid records to create', 'status' => 400], 400, 'No valid records to create'],
            'repeated content' => [['success' => false, 'message' => 'A record with this hostname, type, and content already exists', 'status' => 409], 409, 'A record with this hostname, type, and content already exists'],
            'delete failed' => [['success' => false, 'message' => 'Failed to delete existing record with ID 5', 'status' => 500], 500, 'Failed to delete existing record with ID 5'],
            'insert duplicate' => [
                ['success' => false, 'message' => 'dup', 'status' => 409, 'write' => RecordWriteResult::failure('dup', 409), 'content' => '192.0.2.1'],
                409,
                'A record with this hostname, type, and content already exists',
            ],
            'insert backend fault' => [
                ['success' => false, 'message' => 'db down', 'status' => 500, 'write' => RecordWriteResult::backendFailure('db down'), 'content' => '192.0.2.1'],
                500,
                'Failed to insert record: 192.0.2.1',
            ],
            'insert refused' => [
                ['success' => false, 'message' => 'Content too long', 'status' => 422, 'write' => RecordWriteResult::failure('Content too long', 422), 'content' => '192.0.2.1'],
                422,
                'Content too long',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $outcome
     */
    #[DataProvider('serviceRefusalProvider')]
    public function testAServiceRefusalIsRelayedWithItsStatusAndApiWording(array $outcome, int $status, string $message): void
    {
        $this->replacer->method('replace')->willReturn($outcome);
        $this->records->expects($this->never())->method('getRRSetRecords');

        $response = $this->replace(['name' => 'www', 'type' => 'A', 'ttl' => 60, 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame($message, $this->messageOf($response));
    }

    public function testAFailedReadbackAnswersFromTheValidatedRecords(): void
    {
        $this->replacer->method('replace')->willReturn([
            'success' => true,
            'message' => 'RRSet replaced successfully',
            'status' => 200,
            'name' => 'txt.example.com',
            'records' => [['content' => '"hello"', 'ttl' => 60, 'priority' => 3, 'disabled' => 1]],
        ]);
        $this->records->method('getRRSetRecords')->willThrowException(new \RuntimeException('read failed'));

        $response = $this->replace(['name' => 'txt', 'type' => 'TXT', 'ttl' => 60, 'records' => [['content' => 'hello']]]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['rrset' => [
            'name' => 'txt',
            'type' => 'TXT',
            'ttl' => 60,
            'records' => [['content' => 'hello', 'priority' => 3, 'disabled' => true]],
        ]], $this->decode($response)['data']);
    }

    public function testAnExceptionFromTheServiceIs500WithItsMessage(): void
    {
        $this->replacer->method('replace')->willThrowException(new \RuntimeException('connection lost'));

        $response = $this->replace(['name' => 'www', 'type' => 'A', 'ttl' => 60, 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Failed to replace RRSet: connection lost', $this->messageOf($response));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function replace(array $body): JsonResponse
    {
        return $this->replaceRaw((string)json_encode($body));
    }

    private function replaceRaw(string $rawBody): JsonResponse
    {
        $controller = $this->bareController(ZonesRRSetsController::class);
        $this->injectBaseCollaborators($controller, 'PUT');

        // Re-inject the request with the raw body so malformed payloads survive.
        $request = \Symfony\Component\HttpFoundation\Request::create(
            '/api/v2/zones/' . self::ZONE_ID . '/rrsets',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $rawBody
        );
        $this->inject($controller, 'request', $request);

        $this->zones->method('getZoneById')->willReturn($this->zoneRow);
        $this->domains->method('getDomainNameById')->willReturn($this->zoneName);

        $ttlResolver = $this->createMock(ReverseTtlResolver::class);
        $ttlResolver->method('resolveTtlForType')->willReturn(3600);

        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('domainRepository')->willReturn($this->domains);

        $this->inject($controller, 'serviceFactory', $factory);
        $this->inject($controller, 'zoneRepository', $this->zones);
        $this->inject($controller, 'recordRepository', $this->records);
        $this->inject($controller, 'recordManager', $this->createMock(RecordManagerInterface::class));
        $this->inject($controller, 'apiPermissionService', $this->permissions);
        $this->inject($controller, 'rrsetReplaceService', $this->replacer);
        $this->inject($controller, 'reverseTtlResolver', $ttlResolver);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);
        $this->inject($controller, 'apiKeyScope', $this->scope);
        $this->inject($controller, 'pathParameters', ['id' => $this->zoneIdParameter]);

        return $this->callHandler($controller, 'replaceRRSet');
    }
}
