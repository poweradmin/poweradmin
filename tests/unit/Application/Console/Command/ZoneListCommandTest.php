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

namespace Poweradmin\Tests\Unit\Application\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Console\Command\ZoneListCommand;
use Poweradmin\Application\Console\CommandLineActor;
use Poweradmin\Domain\Model\Constants;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;

#[CoversClass(ZoneListCommand::class)]
#[CoversClass(CommandLineActor::class)]
class ZoneListCommandTest extends TestCase
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        $this->stdout = fopen('php://memory', 'w+');
        $this->stderr = fopen('php://memory', 'w+');
    }

    public function testSystemActorSeesOnlyTheHeaderAndIsToldWhy(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->expects($this->never())->method('getViewPermissionLevel');
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->expects($this->never())->method('getZones');

        $exit = (new ZoneListCommand($permissions, $domains))->run(CommandLineActor::system(), $this->stdout, $this->stderr);

        $this->assertSame(0, $exit);
        $this->assertSame(ZoneListCommand::HEADER . "\n", $this->read($this->stdout));
        $this->assertStringContainsString('--as-user', $this->read($this->stderr));
    }

    public function testUserWithoutViewPermissionSeesNoRows(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getViewPermissionLevel')->with(3)->willReturn('none');
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->expects($this->never())->method('getZones');

        $exit = (new ZoneListCommand($permissions, $domains))->run(new CommandLineActor(3, 'guest'), $this->stdout, $this->stderr);

        $this->assertSame(0, $exit);
        $this->assertSame(ZoneListCommand::HEADER . "\n", $this->read($this->stdout));
    }

    public function testViewLevelAndActorIdReachTheRepositoryAndRowsAreTabSeparated(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getViewPermissionLevel')->with(2)->willReturn('own');
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->expects($this->once())->method('getZones')
            ->with('own', 2, 'all', 0, Constants::DEFAULT_MAX_ROWS, 'name', 'ASC', false, false, false, false, true)
            ->willReturn([
                'example.com' => ['id' => '10', 'name' => 'example.com', 'type' => 'MASTER', 'count_records' => '2'],
                'example.net' => ['id' => 11, 'name' => 'example.net', 'type' => 'NATIVE'],
            ]);

        $exit = (new ZoneListCommand($permissions, $domains))->run(new CommandLineActor(2, 'viewer'), $this->stdout, $this->stderr);

        $this->assertSame(0, $exit);
        $this->assertSame(
            ZoneListCommand::HEADER . "\n10\texample.com\tMASTER\t2\n11\texample.net\tNATIVE\t0\n",
            $this->read($this->stdout)
        );
        $this->assertSame('', $this->read($this->stderr));
    }

    /** @param resource $stream */
    private function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
