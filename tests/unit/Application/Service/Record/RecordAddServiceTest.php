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

namespace Poweradmin\Tests\Unit\Application\Service\Record;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Zone\ChangeApprovalContext;
use Poweradmin\Application\Service\Record\RecordAddAccess;
use Poweradmin\Application\Service\Record\RecordAddMessages;
use Poweradmin\Application\Service\Record\RecordAddResult;
use Poweradmin\Application\Service\Record\RecordAddService;
use Poweradmin\Application\Service\Record\RecordManagerService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\DomainRecordCreator;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\ReverseRecordCreator;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Every add-record entry point goes through one flow: open() gates the zone
 * in a fixed order, IDN input is stored as punycode, bare names get the zone
 * suffix, the type default fills a missing TTL, and the companion PTR or A
 * record is only attempted after the record itself is in.
 */
class RecordAddServiceTest extends TestCase
{
    public function testNormalisesNameAndContentBeforeWriting(): void
    {
        $records = $this->createMock(RecordManagerService::class);
        $records->expects($this->once())->method('createRecord')
            ->with(5, 'xn--bcher-kva.example.com', 'CNAME', 'xn--mnchen-3ya.example.com', 300, 0, 'note', 'alice')
            ->willReturn(RecordWriteResult::ok(1));
        $ttl = $this->createMock(ReverseTtlResolver::class);
        $ttl->method('resolveTtlForType')->with('CNAME', false)->willReturn(300);

        $result = $this->makeService($records, null, null, $ttl)
            ->add(5, 'example.com', 'bücher', 'CNAME', 'münchen.example.com', null, 0, 'note', 7, 'alice');

        $this->assertTrue($result->isOk());
        $this->assertNull($result->companion);
    }

    public function testRefusedWriteSkipsTheCompanion(): void
    {
        $records = $this->createMock(RecordManagerService::class);
        $records->method('createRecord')->willReturn(RecordWriteResult::failure('Invalid IP address'));
        $reverse = $this->createMock(ReverseRecordCreator::class);
        $reverse->expects($this->never())->method('createReverseRecord');

        $result = $this->makeService($records, $reverse)
            ->add(5, 'example.com', 'www', 'A', 'bad', 60, 0, '', 7, 'alice', RecordAddResult::COMPANION_PTR);

        $this->assertFalse($result->isOk());
        $this->assertSame('Invalid IP address', $result->record->message);
        $this->assertNull($result->record->field);
    }

    public function testPtrCompanionUsesTheReverseTtlAndReportsWarnings(): void
    {
        $records = $this->createMock(RecordManagerService::class);
        $records->method('createRecord')->willReturn(RecordWriteResult::ok(1));
        $ttl = $this->createMock(ReverseTtlResolver::class);
        $ttl->method('resolvePtrTtl')->with(60)->willReturn(900);
        $reverse = $this->createMock(ReverseRecordCreator::class);
        $reverse->expects($this->once())->method('createReverseRecord')
            ->with('www.example.com', 'A', '192.0.2.1', 5, 900, 0, '', 'alice')
            ->willReturn(['success' => true, 'type' => 'warning', 'message' => 'A PTR record already points elsewhere.']);

        $result = $this->makeService($records, $reverse, null, $ttl)
            ->add(5, 'example.com', 'www', 'A', '192.0.2.1', 60, 0, '', 7, 'alice', RecordAddResult::COMPANION_PTR);

        $this->assertTrue($result->companionCreated);
        $this->assertTrue($result->companionWarning);
        $this->assertSame(['warning', 'Record successfully added. A PTR record already points elsewhere.'], RecordAddMessages::forAdded($result));
    }

    public function testFailedPtrCompanionKeepsTheRecordAndWordsTheFailure(): void
    {
        $records = $this->createMock(RecordManagerService::class);
        $records->method('createRecord')->willReturn(RecordWriteResult::ok(1));
        $reverse = $this->createMock(ReverseRecordCreator::class);
        $reverse->method('createReverseRecord')->willReturn(['success' => false, 'type' => 'error', 'message' => 'There is no matching reverse-zone for: 1.2.0.192.in-addr.arpa.']);

        $result = $this->makeService($records, $reverse)
            ->add(5, 'example.com', 'www', 'A', '192.0.2.1', 60, 0, '', 7, 'alice', RecordAddResult::COMPANION_PTR);

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->companionCreated);
        $this->assertSame(
            ['warning', 'Record successfully added, but PTR record creation failed: There is no matching reverse-zone for: 1.2.0.192.in-addr.arpa.'],
            RecordAddMessages::forAdded($result)
        );
    }

    public function testAFailedACompanionKeepsTheRecordAndWordsTheFailure(): void
    {
        $records = $this->createMock(RecordManagerService::class);
        $records->method('createRecord')->willReturn(RecordWriteResult::ok(1));
        $domain = $this->createMock(DomainRecordCreator::class);
        $domain->method('addDomainRecord')->willReturn(['success' => false, 'type' => 'error', 'message' => 'no zone']);

        $result = $this->makeService($records, null, $domain)
            ->add(9, '2.0.192.in-addr.arpa', '1', 'PTR', 'www.example.com', 60, 0, '', 7, 'alice', RecordAddResult::COMPANION_A);

        $this->assertSame(RecordAddResult::COMPANION_A, $result->companion);
        $this->assertSame('no zone', $result->companionMessage);
        $this->assertSame(['warning', 'Record successfully added, but A record creation failed: no zone'], RecordAddMessages::forAdded($result));
    }

    public function testPassesTheDisabledFlagThroughToTheWrite(): void
    {
        $records = $this->createMock(RecordManagerService::class);
        $records->expects($this->once())->method('createRecord')
            ->with(5, 'www.example.com', 'A', '192.0.2.1', 60, 0, '', 'alice', 1)
            ->willReturn(RecordWriteResult::ok(1));

        $result = $this->makeService($records)
            ->add(5, 'example.com', 'www', 'A', '192.0.2.1', 60, 0, '', 7, 'alice', '', 1);

        $this->assertTrue($result->isOk());
    }

    public function testRefusesTheWriteWhenTheUserMayNotEditTheZone(): void
    {
        $records = $this->createMock(RecordManagerService::class);
        $records->expects($this->never())->method('createRecord');
        $reverse = $this->createMock(ReverseRecordCreator::class);
        $reverse->expects($this->never())->method('createReverseRecord');
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canEditZoneRecord')->willReturn(false);

        $result = $this->makeService($records, $reverse, permissions: $permissions)
            ->add(5, 'example.com', 'www', 'A', '192.0.2.1', 60, 0, '', 7, 'alice', RecordAddResult::COMPANION_PTR);

        $this->assertFalse($result->isOk());
        $this->assertSame(Refusal::FORBIDDEN, $result->record->refusal);
    }

    public function testChecksThePermissionAgainstTheNormalisedNameAndZoneType(): void
    {
        $records = $this->createMock(RecordManagerService::class);
        $records->method('createRecord')->willReturn(RecordWriteResult::ok(1));
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainType')->with(5)->willReturn('MASTER');
        $permissions = $this->createMock(PermissionService::class);
        $permissions->expects($this->once())->method('canEditZoneRecord')
            ->with(7, 5, 'A', 'MASTER', 'www.example.com', 'example.com')
            ->willReturn(true);

        $result = $this->makeService($records, permissions: $permissions, domains: $domains)
            ->add(5, 'example.com', 'www', 'A', '192.0.2.1', 60, 0, '', 7, 'alice');

        $this->assertTrue($result->isOk());
    }

    // ------------------------------------------------------------- open()

    public function testOpenRefusesAZoneThatDoesNotExist(): void
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->with(5)->willReturn(null);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->expects($this->never())->method('canEditZoneContent');

        $access = $this->makeService($this->createMock(RecordManagerService::class), permissions: $permissions, domains: $domains)->open(5, 7);

        $this->assertSame(RecordAddAccess::ZONE_NOT_FOUND, $access->code);
        $this->assertFalse($access->isGranted());
    }

    public function testOpenSendsAReviewedZoneToTheChangeRequestFlowBeforeTheEditCheck(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->expects($this->never())->method('canEditZoneContent');

        $access = $this->makeService(
            $this->createMock(RecordManagerService::class),
            permissions: $permissions,
            domains: $this->knownZone(),
            approval: $this->approvalAnswering(ChangeApprovalPolicy::MODE_REQUEST)
        )->open(5, 7);

        $this->assertSame(RecordAddAccess::REQUIRES_APPROVAL, $access->code);
        $this->assertSame('example.com', $access->zoneName);
    }

    public function testOpenRefusesAZoneTheUserMayNotWrite(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->expects($this->once())->method('canEditZoneContent')->with(7, 5, 'SLAVE')->willReturn(false);

        $access = $this->makeService(
            $this->createMock(RecordManagerService::class),
            permissions: $permissions,
            domains: $this->knownZone('SLAVE'),
            approval: $this->approvalAnswering(ChangeApprovalPolicy::MODE_DIRECT)
        )->open(5, 7);

        $this->assertSame(RecordAddAccess::FORBIDDEN, $access->code);
        $this->assertSame('SLAVE', $access->zoneType);
    }

    public function testOpenGrantsAWritableZoneAndCarriesItsNameAndType(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canEditZoneContent')->willReturn(true);

        $access = $this->makeService(
            $this->createMock(RecordManagerService::class),
            permissions: $permissions,
            domains: $this->knownZone(),
            approval: $this->approvalAnswering(ChangeApprovalPolicy::MODE_DIRECT)
        )->open(5, 7);

        $this->assertTrue($access->isGranted());
        $this->assertSame(RecordAddAccess::OK, $access->code);
        $this->assertSame('example.com', $access->zoneName);
        $this->assertSame('MASTER', $access->zoneType);
    }

    /** @return DomainRepositoryInterface&MockObject */
    private function knownZone(string $type = 'MASTER'): DomainRepositoryInterface
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->with(5)->willReturn('example.com');
        $domains->method('getDomainType')->with(5)->willReturn($type);

        return $domains;
    }

    /** @return ChangeApprovalContext&MockObject */
    private function approvalAnswering(string $mode): ChangeApprovalContext
    {
        $approval = $this->createMock(ChangeApprovalContext::class);
        $approval->method('modeForZone')->with(7, 5)->willReturn($mode);

        return $approval;
    }

    private function makeService(
        RecordManagerService $records,
        ?ReverseRecordCreator $reverse = null,
        ?DomainRecordCreator $domain = null,
        ?ReverseTtlResolver $ttl = null,
        ?PermissionService $permissions = null,
        ?DomainRepositoryInterface $domains = null,
        ?ChangeApprovalContext $approval = null
    ): RecordAddService {
        if ($permissions === null) {
            $permissions = $this->createMock(PermissionService::class);
            $permissions->method('canEditZoneRecord')->willReturn(true);
        }

        return new RecordAddService(
            $records,
            $reverse ?? $this->createMock(ReverseRecordCreator::class),
            $domain ?? $this->createMock(DomainRecordCreator::class),
            $ttl ?? $this->createMock(ReverseTtlResolver::class),
            $permissions,
            $domains ?? $this->createMock(DomainRepositoryInterface::class),
            $approval ?? $this->createMock(ChangeApprovalContext::class)
        );
    }
}
