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
use Poweradmin\Application\Console\Command\ZoneShowCommand;
use Poweradmin\Application\Console\CommandLineActor;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordListingInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;

#[CoversClass(ZoneShowCommand::class)]
class ZoneShowCommandTest extends TestCase
{
    private const HEADER = "ID\tNAME\tTYPE\tCONTENT\tTTL\tPRIO\tDISABLED\n";

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
        $this->assertSame('zone:show', ZoneShowCommand::name());
        $this->assertSame(['user', 'format'], ZoneShowCommand::options());
        $this->assertNotSame('', ZoneShowCommand::description());
    }

    public function testNumericArgumentIsLookedUpAsAnIdAndRecordsAreTabSeparated(): void
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('zoneIdExists')->with(10)->willReturn(true);
        $domains->expects($this->never())->method('getDomainIdByName');
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canViewZone')->with(2, 10)->willReturn(true);

        $exit = $this->runCommand(new ZoneShowCommand($permissions, $domains, $this->twoRecords()), ['10'], new CommandLineActor(2, 'viewer'));

        $this->assertSame(0, $exit);
        $this->assertSame(
            self::HEADER . "100\texample.com\tSOA\tns1 hostmaster 1\t3600\t0\t0\n101\tmail.example.com\tMX\tmx.example.com\t300\t10\t1\n",
            $this->read($this->stdout)
        );
        $this->assertSame('', $this->read($this->stderr));
    }

    public function testNameArgumentIsResolvedThroughTheDomainRepositoryAndJsonKeepsTypes(): void
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainIdByName')->with('example.com')->willReturn(10);
        $domains->expects($this->never())->method('zoneIdExists');
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canViewZone')->with(1, 10)->willReturn(true);

        $exit = $this->runCommand(
            new ZoneShowCommand($permissions, $domains, $this->twoRecords()),
            ['example.com', '--format=json'],
            new CommandLineActor(1, 'admin')
        );

        $this->assertSame(0, $exit);
        $this->assertSame(
            '[{"id":100,"name":"example.com","type":"SOA","content":"ns1 hostmaster 1","ttl":3600,"prio":0,"disabled":false},'
            . '{"id":101,"name":"mail.example.com","type":"MX","content":"mx.example.com","ttl":300,"prio":10,"disabled":true}]' . "\n",
            $this->read($this->stdout)
        );
    }

    public function testEncodedApiBackendIdsAreKeptAsStrings(): void
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('zoneIdExists')->with(10)->willReturn(true);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canViewZone')->with(1, 10)->willReturn(true);
        $records = $this->createMock(RecordListingInterface::class);
        $records->method('getRecordsFromDomainId')->with(10)->willReturn([
            ['id' => 'd3d3LmV4YW1wbGUuY29tOkE6MTkyLjAuMi4x', 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'prio' => 0, 'disabled' => false],
        ]);

        $exit = $this->runCommand(
            new ZoneShowCommand($permissions, $domains, $records),
            ['10', '--format=json'],
            new CommandLineActor(1, 'admin')
        );

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"id":"d3d3LmV4YW1wbGUuY29tOkE6MTkyLjAuMi4x"', $this->read($this->stdout));
    }

    public function testUnknownZoneExitsOneBeforeAnyPermissionCheck(): void
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainIdByName')->willReturn(null);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->expects($this->never())->method('canViewZone');

        $exit = $this->runCommand(new ZoneShowCommand($permissions, $domains, $this->noRecords()), ['nope.example'], new CommandLineActor(1, 'admin'));

        $this->assertSame(1, $exit);
        $this->assertSame('', $this->read($this->stdout));
        $this->assertStringContainsString('Zone "nope.example" does not exist', $this->read($this->stderr));
    }

    public function testActorWithoutViewPermissionIsRefused(): void
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('zoneIdExists')->willReturn(true);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canViewZone')->with(3, 10)->willReturn(false);

        $exit = $this->runCommand(new ZoneShowCommand($permissions, $domains, $this->noRecords()), ['10'], new CommandLineActor(3, 'guest'));

        $this->assertSame(1, $exit);
        $this->assertSame('', $this->read($this->stdout));
        $this->assertStringContainsString('may not view zone 10', $this->read($this->stderr));
    }

    public function testSystemActorIsRefusedWithoutAskingThePermissionService(): void
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('zoneIdExists')->willReturn(true);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->expects($this->never())->method('canViewZone');

        $exit = $this->runCommand(new ZoneShowCommand($permissions, $domains, $this->noRecords()), ['10'], CommandLineActor::system());

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--as-user', $this->read($this->stderr));
    }

    public function testArgumentCountIsAUsageError(): void
    {
        $command = new ZoneShowCommand(
            $this->createMock(PermissionService::class),
            $this->createMock(DomainRepositoryInterface::class),
            $this->noRecords()
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zone:show expects exactly one argument');

        $this->runCommand($command, ['10', '11'], new CommandLineActor(1, 'admin'));
    }

    private function twoRecords(): RecordListingInterface
    {
        $records = $this->createMock(RecordListingInterface::class);
        $records->expects($this->once())->method('getRecordsFromDomainId')->with(10)->willReturn([
            ['id' => '100', 'name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1 hostmaster 1', 'ttl' => '3600', 'prio' => null, 'disabled' => false],
            ['id' => 101, 'name' => 'mail.example.com', 'type' => 'MX', 'content' => 'mx.example.com', 'ttl' => 300, 'prio' => 10, 'disabled' => true],
        ]);

        return $records;
    }

    private function noRecords(): RecordListingInterface
    {
        $records = $this->createMock(RecordListingInterface::class);
        $records->expects($this->never())->method('getRecordsFromDomainId');

        return $records;
    }

    /** @param list<string> $argv The words after the command name */
    private function runCommand(ZoneShowCommand $command, array $argv, CommandLineActor $actor): int
    {
        return $command->run(Arguments::parse(['zone:show', ...$argv]), $actor, $this->stdout, $this->stderr);
    }

    /** @param resource $stream */
    private function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
