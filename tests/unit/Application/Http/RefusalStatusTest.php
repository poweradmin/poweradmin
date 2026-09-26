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

namespace Poweradmin\Tests\Unit\Application\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\RefusalStatus;
use Poweradmin\Domain\Service\Auth\ApiKeyWriteResult;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\SupermasterWriteResult;
use Poweradmin\Domain\Service\Dns\ZoneWriteResult;
use Poweradmin\Domain\Service\Template\ZoneTemplateWriteResult;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestResult;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipResolution;
use ReflectionClass;

/**
 * Pins the HTTP status every Domain refusal maps to. The factory rows call the
 * real result factories; the ERR_* rows record the statuses each service code
 * produces so the mapping table stays equivalent when the field changes shape.
 */
class RefusalStatusTest extends TestCase
{
    /** @return iterable<string, array{Refusal, int}> */
    public static function refusals(): iterable
    {
        yield 'INVALID_INPUT' => [Refusal::INVALID_INPUT, 400];
        yield 'FORBIDDEN' => [Refusal::FORBIDDEN, 403];
        yield 'NOT_FOUND' => [Refusal::NOT_FOUND, 404];
        yield 'CONFLICT' => [Refusal::CONFLICT, 409];
        yield 'PAYLOAD_TOO_LARGE' => [Refusal::PAYLOAD_TOO_LARGE, 413];
        yield 'BACKEND_FAILURE' => [Refusal::BACKEND_FAILURE, 500];
    }

    #[DataProvider('refusals')]
    public function testOfMapsEveryRefusal(Refusal $refusal, int $expectedStatus): void
    {
        $this->assertSame($expectedStatus, RefusalStatus::of($refusal));
    }

    public function testOfResultPrefersRefusalOverStatus(): void
    {
        $this->assertSame(403, RefusalStatus::ofResult(['refusal' => Refusal::FORBIDDEN, 'status' => 400]));
    }

    public function testOfResultFallsBackToStatus(): void
    {
        $this->assertSame(409, RefusalStatus::ofResult(['status' => 409]));
    }

    public function testOfResultFallsBackToDefaultStatus(): void
    {
        $this->assertSame(400, RefusalStatus::ofResult([]));
        $this->assertSame(422, RefusalStatus::ofResult([], 422));
    }

    /** @return iterable<string, array{callable(): object, int}> */
    public static function resultFactories(): iterable
    {
        yield 'ZoneWriteResult::failure default' => [fn() => ZoneWriteResult::failure('m'), 400];
        yield 'ZoneWriteResult::failure not found' => [fn() => ZoneWriteResult::failure('m', Refusal::NOT_FOUND), 404];
        yield 'ZoneWriteResult::forbidden' => [fn() => ZoneWriteResult::forbidden('m'), 403];
        yield 'ZoneWriteResult::backendFailure' => [fn() => ZoneWriteResult::backendFailure('m'), 500];

        yield 'RecordWriteResult::failure default' => [fn() => RecordWriteResult::failure('m'), 400];
        yield 'RecordWriteResult::failure conflict' => [fn() => RecordWriteResult::failure('m', Refusal::CONFLICT), 409];
        yield 'RecordWriteResult::forbidden' => [fn() => RecordWriteResult::forbidden('m'), 403];
        yield 'RecordWriteResult::notFound' => [fn() => RecordWriteResult::notFound('m'), 404];
        yield 'RecordWriteResult::backendFailure' => [fn() => RecordWriteResult::backendFailure('m'), 500];

        yield 'SupermasterWriteResult::refused default' => [fn() => SupermasterWriteResult::refused(SupermasterWriteResult::ERR_INVALID_IP, 'm'), 400];
        yield 'SupermasterWriteResult::refused exists' => [fn() => SupermasterWriteResult::refused(SupermasterWriteResult::ERR_EXISTS, 'm', Refusal::CONFLICT), 409];
        yield 'SupermasterWriteResult::refused not found' => [fn() => SupermasterWriteResult::refused(SupermasterWriteResult::ERR_NOT_FOUND, 'm', Refusal::NOT_FOUND), 404];
        yield 'SupermasterWriteResult::refused backend' => [fn() => SupermasterWriteResult::refused(SupermasterWriteResult::ERR_BACKEND, 'm', Refusal::BACKEND_FAILURE), 500];

        yield 'ApiKeyWriteResult::refused forbidden' => [fn() => ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_FORBIDDEN, 'm', Refusal::FORBIDDEN), 403];
        yield 'ApiKeyWriteResult::refused limit' => [fn() => ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_LIMIT, 'm', Refusal::CONFLICT), 409];
        yield 'ApiKeyWriteResult::refused not found' => [fn() => ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_NOT_FOUND, 'm', Refusal::NOT_FOUND), 404];
        yield 'ApiKeyWriteResult::refused write' => [fn() => ApiKeyWriteResult::refused(ApiKeyWriteResult::ERR_WRITE, 'm', Refusal::BACKEND_FAILURE), 500];

        yield 'ZoneTemplateWriteResult::failure default' => [fn() => ZoneTemplateWriteResult::failure('m'), 400];
        yield 'ZoneTemplateWriteResult::failure not found' => [fn() => ZoneTemplateWriteResult::failure('m', Refusal::NOT_FOUND), 404];
        yield 'ZoneTemplateWriteResult::failure conflict' => [fn() => ZoneTemplateWriteResult::failure('m', Refusal::CONFLICT), 409];
        yield 'ZoneTemplateWriteResult::forbidden' => [fn() => ZoneTemplateWriteResult::forbidden('m'), 403];
        yield 'ZoneTemplateWriteResult::backendFailure' => [fn() => ZoneTemplateWriteResult::backendFailure('m'), 500];

        yield 'ZoneChangeRequestResult::failure default' => [fn() => ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_NO_CHANGES, 'm'), 400];
        yield 'ZoneChangeRequestResult::failure not found' => [fn() => ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_NOT_FOUND, 'm', Refusal::NOT_FOUND), 404];
        yield 'ZoneChangeRequestResult::failure decided' => [fn() => ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_NOT_PENDING, 'm', Refusal::CONFLICT), 409];
        yield 'ZoneChangeRequestResult::failure read only' => [fn() => ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_READ_ONLY_ZONE, 'm', Refusal::FORBIDDEN), 403];
        yield 'ZoneChangeRequestResult::failure too large' => [fn() => ZoneChangeRequestResult::failure(ZoneChangeRequestResult::CODE_PAYLOAD_TOO_LARGE, 'm', Refusal::PAYLOAD_TOO_LARGE), 413];

        yield 'ZoneOwnershipResolution::error invalid' => [fn() => ZoneOwnershipResolution::error('m', Refusal::INVALID_INPUT, ZoneOwnershipResolution::NO_OWNER), 400];
        yield 'ZoneOwnershipResolution::error forbidden' => [fn() => ZoneOwnershipResolution::error('m', Refusal::FORBIDDEN, ZoneOwnershipResolution::OTHER_OWNER_FORBIDDEN), 403];
        yield 'ZoneOwnershipResolution::error unknown' => [fn() => ZoneOwnershipResolution::error('m', Refusal::NOT_FOUND, ZoneOwnershipResolution::UNKNOWN_OWNER), 404];
    }

    #[DataProvider('resultFactories')]
    public function testResultFactoryStatus(callable $factory, int $expectedStatus): void
    {
        $result = $factory();

        $this->assertFalse($result->success ?? false);
        $this->assertInstanceOf(Refusal::class, $result->refusal);
        $this->assertSame($expectedStatus, RefusalStatus::of($result->refusal));
    }

    /**
     * Every ERR_* code of the array-returning services and the statuses its
     * branches produce today. Codes that relay a nested write carry the
     * statuses that write can produce.
     *
     * @return array<string, list<int>>
     */
    private static function userManagementStatuses(): array
    {
        return [
            UserManagementService::ERR_USERNAME_REQUIRED => [400],
            UserManagementService::ERR_INVALID_LDAP => [400],
            UserManagementService::ERR_PASSWORD_REQUIRED => [400],
            UserManagementService::ERR_PASSWORD_POLICY => [400],
            UserManagementService::ERR_FIELD_LENGTH => [400],
            UserManagementService::ERR_USERNAME_EXISTS => [409],
            UserManagementService::ERR_EMAIL_EXISTS => [409],
            UserManagementService::ERR_INVALID_EMAIL => [400],
            UserManagementService::ERR_NO_TEMPLATE => [400],
            UserManagementService::ERR_TEMPLATE_NOT_FOUND => [400],
            UserManagementService::ERR_NOT_FOUND => [404],
            UserManagementService::ERR_PASSWORD_FORBIDDEN => [400],
            UserManagementService::ERR_LAST_ADMIN => [409],
            UserManagementService::ERR_TRANSFER_TARGET => [400, 404],
            UserManagementService::ERR_ZONE_DELETE_FORBIDDEN => [403],
            UserManagementService::ERR_ZONE_META_FORBIDDEN => [403],
            UserManagementService::ERR_ZONE_WRITE => [400, 403, 404, 500],
            UserManagementService::ERR_WRITE => [500],
        ];
    }

    /** @return array<string, list<int>> */
    private static function zoneManagementStatuses(): array
    {
        return [
            ZoneManagementService::ERR_NO_OWNER => [400],
            ZoneManagementService::ERR_INVALID_NAME => [400],
            ZoneManagementService::ERR_EXISTS => [409],
            ZoneManagementService::ERR_OVERLAP => [409],
            ZoneManagementService::ERR_INVALID_TYPE => [400],
            ZoneManagementService::ERR_MASTER_REQUIRED => [400],
            ZoneManagementService::ERR_INVALID_MASTER => [400],
            ZoneManagementService::ERR_INVALID_SOA_EDIT_API => [400],
            ZoneManagementService::ERR_TEMPLATE_NOT_FOUND => [404],
            ZoneManagementService::ERR_TEMPLATE_AMBIGUOUS => [409],
            ZoneManagementService::ERR_TEMPLATE_FORBIDDEN => [403],
            ZoneManagementService::ERR_ZONE_WRITE => [400, 403, 404, 500],
            ZoneManagementService::ERR_NOT_FOUND => [404],
            ZoneManagementService::ERR_READ_ONLY => [400],
        ];
    }

    /** @return iterable<string, array{class-string, array<string, list<int>>}> */
    public static function serviceCodeTables(): iterable
    {
        yield 'UserManagementService' => [UserManagementService::class, self::userManagementStatuses()];
        yield 'ZoneManagementService' => [ZoneManagementService::class, self::zoneManagementStatuses()];
    }

    /**
     * @param class-string $service
     * @param array<string, list<int>> $table
     */
    #[DataProvider('serviceCodeTables')]
    public function testEveryServiceCodeIsTabulated(string $service, array $table): void
    {
        $codes = [];
        foreach ((new ReflectionClass($service))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'ERR_')) {
                $codes[] = $value;
            }
        }

        $this->assertEqualsCanonicalizing($codes, array_keys($table));
        foreach ($table as $code => $statuses) {
            $this->assertNotEmpty($statuses, $code);
            foreach ($statuses as $status) {
                $this->assertContains($status, [400, 403, 404, 409, 500], $code);
            }
        }
    }
}
