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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserGroup;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Service\GroupReferenceResolver;

#[CoversClass(GroupReferenceResolver::class)]
class GroupReferenceResolverTest extends TestCase
{
    private const MAX_ENTRIES = 50;

    private UserGroupRepositoryInterface&MockObject $groupRepository;
    private GroupReferenceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->groupRepository = $this->createMock(UserGroupRepositoryInterface::class);
        $this->resolver = new GroupReferenceResolver($this->groupRepository);
    }

    #[Test]
    public function testResolvesIntegerId(): void
    {
        $group = new UserGroup(3, 'dns-operators', null, 7);

        $this->groupRepository->method('findById')->with(3)->willReturn($group);

        $result = $this->resolver->resolve([3], self::MAX_ENTRIES);

        $this->assertTrue($result['success']);
        $this->assertSame([3 => $group], $result['groups']);
    }

    #[Test]
    public function testResolvesExactName(): void
    {
        $group = new UserGroup(3, 'dns-operators', null, 7);

        $this->groupRepository->method('findByName')->with('dns-operators')->willReturn($group);

        $result = $this->resolver->resolve(['dns-operators'], self::MAX_ENTRIES);

        $this->assertTrue($result['success']);
        $this->assertSame([3 => $group], $result['groups']);
    }

    #[Test]
    public function testRejectsNameDifferingOnlyByCase(): void
    {
        // MySQL's utf8mb4_unicode_ci returns the row for a differently cased name; the
        // resolver must not accept it, or the same request would behave differently on
        // PostgreSQL and SQLite.
        $this->groupRepository->method('findByName')
            ->with('DNS-Operators')
            ->willReturn(new UserGroup(3, 'dns-operators', null, 7));

        $result = $this->resolver->resolve(['DNS-Operators'], self::MAX_ENTRIES);

        $this->assertFalse($result['success']);
        $this->assertSame('Group not found: DNS-Operators', $result['message']);
        $this->assertSame(400, $result['status']);
    }

    #[Test]
    public function testRejectsUnknownName(): void
    {
        $this->groupRepository->method('findByName')->willReturn(null);

        $result = $this->resolver->resolve(['no-such-group'], self::MAX_ENTRIES);

        $this->assertFalse($result['success']);
        $this->assertSame('Group not found: no-such-group', $result['message']);
    }

    #[Test]
    public function testRejectsUnknownId(): void
    {
        $this->groupRepository->method('findById')->willReturn(null);

        $result = $this->resolver->resolve([999], self::MAX_ENTRIES);

        $this->assertFalse($result['success']);
        $this->assertSame('Group not found: 999', $result['message']);
    }

    #[Test]
    public function testTreatsNumericStringAsNameNotId(): void
    {
        $this->groupRepository->expects($this->never())->method('findById');
        $this->groupRepository->expects($this->once())->method('findByName')->with('3')->willReturn(null);

        $result = $this->resolver->resolve(['3'], self::MAX_ENTRIES);

        $this->assertFalse($result['success']);
        $this->assertSame('Group not found: 3', $result['message']);
    }

    #[Test]
    public function testCollapsesIdAndItsOwnNameIntoOneEntry(): void
    {
        $group = new UserGroup(3, 'dns-operators', null, 7);

        $this->groupRepository->method('findById')->with(3)->willReturn($group);
        $this->groupRepository->method('findByName')->with('dns-operators')->willReturn($group);

        $result = $this->resolver->resolve([3, 'dns-operators'], self::MAX_ENTRIES);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['groups']);
    }

    #[Test]
    public function testRejectsNonListValues(): void
    {
        foreach ([3, 'dns-operators', ['groups' => 3], null] as $submitted) {
            $result = $this->resolver->resolve($submitted, self::MAX_ENTRIES);

            $this->assertFalse($result['success']);
            $this->assertSame('groups must be an array of group IDs or names', $result['message']);
        }
    }

    #[Test]
    public function testRejectsEntriesThatAreNeitherIntNorString(): void
    {
        foreach ([[3.0], [true], [null], [[3]]] as $submitted) {
            $result = $this->resolver->resolve($submitted, self::MAX_ENTRIES);

            $this->assertFalse($result['success']);
            $this->assertSame('groups entries must be integer IDs or group names', $result['message']);
        }
    }

    #[Test]
    public function testRejectsListOverTheCap(): void
    {
        $this->groupRepository->expects($this->never())->method('findById');

        $result = $this->resolver->resolve(range(1, 51), self::MAX_ENTRIES);

        $this->assertFalse($result['success']);
        $this->assertSame('groups accepts at most 50 entries per request', $result['message']);
    }

    #[Test]
    public function testAcceptsEmptyList(): void
    {
        $result = $this->resolver->resolve([], self::MAX_ENTRIES);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['groups']);
    }
}
