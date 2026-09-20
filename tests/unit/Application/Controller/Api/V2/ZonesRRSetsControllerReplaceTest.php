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
use Poweradmin\Domain\Service\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Characterization of ZonesRRSetsController::replaceRRSet - the gate order and
 * every input-validation refusal it can answer with before it touches the DNS
 * validation service. The rules here are pinned as the code behaves today.
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
    public static function nonPositiveTtlProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            // Numeric strings are coerced first, so "0" fails the same range check.
            'numeric string zero' => ['0'],
        ];
    }

    #[DataProvider('nonPositiveTtlProvider')]
    public function testATtlBelowOneIs400(int|string $ttl): void
    {
        $response = $this->replace(['name' => 'www', 'type' => 'A', 'ttl' => $ttl, 'records' => [['content' => '192.0.2.1']]]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('TTL must be greater than 0', $this->messageOf($response));
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
        $this->inject($controller, 'backendProvider', $this->createMock(DnsBackendProviderInterface::class));
        $this->inject($controller, 'reverseTtlResolver', $ttlResolver);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);
        $this->inject($controller, 'apiKeyScope', $this->scope);
        $this->inject($controller, 'pathParameters', ['id' => $this->zoneIdParameter]);

        return $this->callHandler($controller, 'replaceRRSet');
    }
}
