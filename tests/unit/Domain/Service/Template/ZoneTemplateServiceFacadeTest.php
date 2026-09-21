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

namespace Poweradmin\Tests\Unit\Domain\Service\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Template\ZoneTemplateAccessPolicy;
use Poweradmin\Domain\Service\Template\ZoneTemplateRecordService;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Domain\Service\Template\ZoneTemplateWriteResult;
use Poweradmin\Domain\Service\Template\ZoneTemplateWriteService;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the facade: every public method forwards to the write, record or access
 * service (or straight to the repository) with its arguments unchanged. The
 * method list is taken by reflection so a dropped or unmapped method fails.
 */
class ZoneTemplateServiceFacadeTest extends TestCase
{
    private const ACCESS = 'access';
    private const WRITES = 'writes';
    private const RECORDS = 'records';
    private const REPOSITORY = 'repository';

    /**
     * facade method => [collaborator, collaborator method, arguments, return value]
     *
     * @return array<string, array{string, string, array, mixed}>
     */
    private static function delegations(): array
    {
        $result = ZoneTemplateWriteResult::ok();
        $record = ['rid' => 7, 'name' => 'www', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 300, 'prio' => 0];
        $details = ['templ_name' => 'n', 'templ_descr' => 'd', 'templ_global' => '1'];

        return [
            'getDefaultTemplateId' => [self::WRITES, 'getDefaultTemplateId', [], 4],
            'setDefaultTemplate' => [self::WRITES, 'setDefaultTemplate', [4], $result],
            'unsetDefaultTemplate' => [self::WRITES, 'unsetDefaultTemplate', [], $result],
            'addZoneTempl' => [self::WRITES, 'addZoneTempl', [$details, 3], $result],
            'editZoneTempl' => [self::WRITES, 'editZoneTempl', [$details, 4, 3], $result],
            'deleteZoneTempl' => [self::WRITES, 'deleteZoneTempl', [4], $result],
            'addZoneTemplSaveAs' => [self::WRITES, 'addZoneTemplSaveAs', ['n', 'd', 3, [$record], ['global' => true], 'example.com'], $result],
            'addZoneTemplRecord' => [self::RECORDS, 'addZoneTemplRecord', [4, 'www', 'A', '192.0.2.1', 300, 0], $result],
            'editZoneTemplRecord' => [self::RECORDS, 'editZoneTemplRecord', [$record, 4], $result],
            'deleteZoneTemplRecord' => [self::RECORDS, 'deleteZoneTemplRecord', [7, 4], $result],
            'canUseTemplate' => [self::ACCESS, 'canUseTemplate', ['4', 3, false], true],
            'canCurrentUserUseTemplate' => [self::ACCESS, 'canCurrentUserUseTemplate', ['4'], true],
            'isUserOwnerOfTemplate' => [self::ACCESS, 'isUserOwnerOfTemplate', [4, 3], true],
            'unlinkZoneFromTemplate' => [self::REPOSITORY, 'unlinkZoneFromTemplate', [9], true],
            'getZonesByIds' => [self::REPOSITORY, 'getZonesByIds', [[9, 10]], [['id' => 9]]],
            'zoneTemplIdExists' => [self::REPOSITORY, 'zoneTemplateExists', [4], true],
            'zoneTemplNameExists' => [self::REPOSITORY, 'zoneTemplateNameExists', ['n'], true],
            'getZoneTemplIdsByName' => [self::REPOSITORY, 'findTemplateIdsByName', ['n'], [4, 5]],
            'zoneTemplNameAndIdExists' => [self::REPOSITORY, 'zoneTemplateNameExists', ['n', 4], true],
        ];
    }

    /**
     * Facade methods that combine an access-policy answer with a repository read.
     *
     * facade method => [arguments, access method, access return, repository method, repository arguments, return value]
     *
     * @return array<string, array{array, string, mixed, string, array, mixed}>
     */
    private static function scopedReads(): array
    {
        return [
            'getListZoneTempl' => [[3], 'currentUserHasPermission', true, 'listZoneTemplates', [3, true], [['id' => 4]]],
            'getListZoneUseTempl' => [[4, 3], 'linkedZoneOwnerFilter', 3, 'listLinkedZoneIds', [4, 3], [9]],
            'getZoneAndDomainIdsByTemplate' => [[4, 3], 'linkedZoneOwnerFilter', null, 'listLinkedZoneIdPairs', [4, null], [['zone_id' => 9, 'domain_id' => 9]]],
            'getZonesUsingTemplate' => [[4, 3], 'linkedZoneOwnerFilter', 3, 'listLinkedZones', [4, 3], [['id' => 9]]],
        ];
    }

    public function testEveryPublicMethodIsMapped(): void
    {
        $public = array_map(
            static fn(ReflectionMethod $m): string => $m->getName(),
            array_filter(
                (new ReflectionClass(ZoneTemplateService::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn(ReflectionMethod $m): bool => !$m->isConstructor()
            )
        );
        sort($public);

        $mapped = array_merge(array_keys(self::delegations()), array_keys(self::scopedReads()));
        sort($mapped);

        $this->assertSame($mapped, array_values($public));
    }

    public static function delegationProvider(): iterable
    {
        foreach (self::delegations() as $method => $case) {
            yield $method => [$method, ...$case];
        }
    }

    #[DataProvider('delegationProvider')]
    public function testForwardsToTheCollaborator(string $method, string $collaborator, string $target, array $args, mixed $return): void
    {
        $mocks = $this->collaborators();
        $mocks[$collaborator]->expects($this->once())->method($target)->with(...$args)->willReturn($return);

        $facade = new ZoneTemplateService($mocks[self::REPOSITORY], $mocks[self::ACCESS], $mocks[self::WRITES], $mocks[self::RECORDS]);

        $this->assertSame($return, $facade->$method(...$args));
    }

    public static function scopedReadProvider(): iterable
    {
        foreach (self::scopedReads() as $method => $case) {
            yield $method => [$method, ...$case];
        }
    }

    #[DataProvider('scopedReadProvider')]
    public function testScopesRepositoryReadsThroughTheAccessPolicy(
        string $method,
        array $args,
        string $accessMethod,
        mixed $accessReturn,
        string $repositoryMethod,
        array $repositoryArgs,
        mixed $return
    ): void {
        $mocks = $this->collaborators();
        $accessExpectation = $mocks[self::ACCESS]->expects($this->once())->method($accessMethod)->willReturn($accessReturn);
        if ($accessMethod === 'currentUserHasPermission') {
            $accessExpectation->with(Permission::PERM_USER_IS_UEBERUSER);
        } else {
            $accessExpectation->with($args[1]);
        }
        $mocks[self::REPOSITORY]->expects($this->once())->method($repositoryMethod)->with(...$repositoryArgs)->willReturn($return);

        $facade = new ZoneTemplateService($mocks[self::REPOSITORY], $mocks[self::ACCESS], $mocks[self::WRITES], $mocks[self::RECORDS]);

        $this->assertSame($return, $facade->$method(...$args));
    }

    public function testGetZonesByIdsSkipsTheRepositoryForNoIds(): void
    {
        $mocks = $this->collaborators();
        $mocks[self::REPOSITORY]->expects($this->never())->method('getZonesByIds');

        $facade = new ZoneTemplateService($mocks[self::REPOSITORY], $mocks[self::ACCESS], $mocks[self::WRITES], $mocks[self::RECORDS]);

        $this->assertSame([], $facade->getZonesByIds([]));
    }

    /**
     * @return array<string, \PHPUnit\Framework\MockObject\MockObject>
     */
    private function collaborators(): array
    {
        return [
            self::REPOSITORY => $this->createMock(ZoneTemplateRepositoryInterface::class),
            self::ACCESS => $this->createMock(ZoneTemplateAccessPolicy::class),
            self::WRITES => $this->createMock(ZoneTemplateWriteService::class),
            self::RECORDS => $this->createMock(ZoneTemplateRecordService::class),
        ];
    }
}
