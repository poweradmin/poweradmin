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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Console\Arguments;
use Poweradmin\Application\Console\Command\ZoneListCommand;
use Poweradmin\Application\Console\CommandLineActor;
use Poweradmin\Domain\Model\Constants;
use Poweradmin\Domain\Model\ZoneSummary;
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

    public function testMetadataNamesTheCommandAndItsOptions(): void
    {
        $this->assertSame('zone:list', ZoneListCommand::name());
        $this->assertSame(['user', 'format'], ZoneListCommand::options());
        $this->assertNotSame('', ZoneListCommand::description());
    }

    public function testSystemActorSeesOnlyTheHeaderAndIsToldWhy(): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->expects($this->never())->method('getViewPermissionLevel');
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->expects($this->never())->method('getZones');

        $exit = $this->runCommand(new ZoneListCommand($permissions, $domains), [], CommandLineActor::system());

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

        $exit = $this->runCommand(new ZoneListCommand($permissions, $domains), [], new CommandLineActor(3, 'guest'));

        $this->assertSame(0, $exit);
        $this->assertSame(ZoneListCommand::HEADER . "\n", $this->read($this->stdout));
    }

    public function testViewLevelAndActorIdReachTheRepositoryAndRowsAreTabSeparated(): void
    {
        $command = new ZoneListCommand($this->viewerPermissions(), $this->twoZones());

        $exit = $this->runCommand($command, [], new CommandLineActor(2, 'viewer'));

        $this->assertSame(0, $exit);
        $this->assertSame(
            ZoneListCommand::HEADER . "\n10\texample.com\tMASTER\t2\n11\texample.net\tNATIVE\t0\n",
            $this->read($this->stdout)
        );
        $this->assertSame('', $this->read($this->stderr));
    }

    public function testJsonFormatWritesOneObjectPerZone(): void
    {
        $command = new ZoneListCommand($this->viewerPermissions(), $this->twoZones());

        $exit = $this->runCommand($command, ['--format=json'], new CommandLineActor(2, 'viewer'));

        $this->assertSame(0, $exit);
        $this->assertSame(
            '[{"id":10,"name":"example.com","type":"MASTER","records":2},{"id":11,"name":"example.net","type":"NATIVE","records":0}]' . "\n",
            $this->read($this->stdout)
        );
    }

    public function testStrayArgumentIsAUsageError(): void
    {
        $command = new ZoneListCommand(
            $this->createMock(PermissionService::class),
            $this->createMock(DomainRepositoryInterface::class)
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zone:list takes no arguments');

        $this->runCommand($command, ['example.com'], new CommandLineActor(2, 'viewer'));
    }

    private function viewerPermissions(): PermissionService
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getViewPermissionLevel')->with(2)->willReturn('own');

        return $permissions;
    }

    private function twoZones(): DomainRepositoryInterface
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->expects($this->once())->method('getZones')
            ->with('own', 2, 'all', 0, Constants::DEFAULT_MAX_ROWS, 'name', 'ASC', false, false, false, false, true)
            ->willReturn([
                'example.com' => ZoneSummary::fromRow(['id' => '10', 'name' => 'example.com', 'type' => 'MASTER', 'count_records' => '2']),
                'example.net' => ZoneSummary::fromRow(['id' => 11, 'name' => 'example.net', 'type' => 'NATIVE']),
            ]);

        return $domains;
    }

    /** @param list<string> $argv The words after the command name */
    private function runCommand(ZoneListCommand $command, array $argv, CommandLineActor $actor): int
    {
        return $command->run(Arguments::parse(['zone:list', ...$argv]), $actor, $this->stdout, $this->stderr);
    }

    /** @param resource $stream */
    private function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
