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

namespace Poweradmin\Tests\Unit\Dns;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Service\ApiDnsBackendProvider;
use Psr\Log\NullLogger;

/**
 * updateSOASerial() on the API backend against a PowerDNS stand-in that edits the
 * SOA of every PATCH the way each SOA-EDIT-API value does (checked live against
 * PowerDNS 5.x): DEFAULT and INCREASE add one to the stored serial, EPOCH sets the
 * clock, OFF and unset store exactly what was sent.
 */
class SOARecordManagerApiSerialTest extends TestCase
{
    /** Content as PowerDNS stores it, with dotted names. */
    private string $stored;
    private int $patches;
    /** @var list<string> */
    private array $sent;
    /** @var list<int> */
    private array $sentTtl;

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function modes(): array
    {
        return ['unset' => [null], 'empty' => [''], 'OFF' => ['OFF'], 'DEFAULT' => ['DEFAULT'], 'INCREASE' => ['INCREASE'], 'EPOCH' => ['EPOCH']];
    }

    #[DataProvider('modes')]
    public function testAWriteThatSentAnRrsetLeavesTheBumpToPowerDnsOnlyWhenItEditsTheSerial(?string $mode): void
    {
        $manager = $this->manager($mode);
        $before = $this->serial();

        $this->assertTrue($manager->updateSOASerial(7));

        if ($this->editsSerial($mode)) {
            $this->assertSame(0, $this->patches, 'PowerDNS bumps for the RRset already sent');
            $this->assertSame($before, $this->serial());
        } else {
            $this->assertSame(1, $this->patches);
            $this->assertNotSame($before, $this->serial(), 'with SOA-EDIT-API off nobody else moves the serial');
        }
    }

    #[DataProvider('modes')]
    public function testASaveThatSentNoRrsetStillMovesTheSerial(?string $mode): void
    {
        $manager = $this->manager($mode);
        $before = $this->serial();

        $moved = $manager->updateSOASerial(7, false);

        $this->assertSame(1, $this->patches);
        if ($mode === 'EPOCH') {
            // The clock stand-in may land on the stored value; the result must say so
            $this->assertSame($this->serial() !== $before, $moved);

            return;
        }
        $this->assertTrue($moved);
        $this->assertNotSame($before, $this->serial());
    }

    public function testAnOffZoneGetsTheNextSerialSentByPoweradmin(): void
    {
        $manager = $this->manager('OFF');

        $this->assertTrue($manager->updateSOASerial(7, false));

        $this->assertSame((string)$manager->getNextSerial('2024010101'), $this->serial());
    }

    public function testTheSoaIsResentUnchangedWhenPowerDnsDoesTheBump(): void
    {
        $manager = $this->manager('DEFAULT');

        $manager->updateSOASerial(7, false);

        $this->assertStringContainsString(' 2024010101 10800 ', $this->sent[0]);
    }

    public function testTheSoaKeepsItsTtlWhenTheSerialIsRewritten(): void
    {
        $this->manager('OFF')->updateSOASerial(7);

        $this->assertSame([3600], $this->sentTtl);
    }

    public function testABumpPowerDnsDidNotApplyIsReportedAsFailed(): void
    {
        $manager = $this->manager('DEFAULT', frozen: true);

        $this->assertFalse($manager->updateSOASerial(7, false));
    }

    private function editsSerial(?string $mode): bool
    {
        return $mode !== null && $mode !== '' && $mode !== 'OFF';
    }

    private function serial(): string
    {
        return explode(' ', $this->stored)[2];
    }

    private function manager(?string $mode, bool $frozen = false): SOARecordManager
    {
        $this->stored = 'ns1.example.com. hostmaster.example.com. 2024010101 10800 3600 604800 3600';
        $this->patches = 0;
        $this->sent = [];
        $this->sentTtl = [];

        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn(['id' => 7, 'zone_name' => 'example.com', 'zone_type' => 'MASTER']);
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturn($statement);

        $client = $this->createMock(PowerdnsApiClient::class);
        $client->method('getZone')->willReturnCallback(fn() => $mode === null ? ['kind' => 'Master'] : ['soa_edit_api' => $mode]);
        $client->method('getZoneRrset')->willReturnCallback(fn() => ['rrsets' => [[
            'name' => 'example.com.',
            'type' => 'SOA',
            'ttl' => 3600,
            'records' => [['content' => $this->stored, 'disabled' => false]],
        ]]]);
        $client->method('patchZoneRRsets')->willReturnCallback(function (string $zone, array $rrsets) use ($mode, $frozen): bool {
            $this->patches++;
            $content = $rrsets[0]['records'][0]['content'];
            $this->sent[] = $content;
            $this->sentTtl[] = $rrsets[0]['ttl'];
            $fields = explode(' ', $content);
            if (!$frozen && $this->editsSerial($mode)) {
                $stored = (int)explode(' ', $this->stored)[2];
                $fields[2] = (string)($mode === 'EPOCH' ? max($stored + 1, 1792786668) : $stored + 1);
            }
            $this->stored = implode(' ', $fields);

            return true;
        });

        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(fn($group, $key, $default = null) => $default);

        $provider = new ApiDnsBackendProvider($client, $db, $config, new NullLogger());
        $managerConfig = $this->createMock(ConfigurationManager::class);
        $managerConfig->method('get')->willReturnCallback(fn($group, $key, $default = null) => $default);

        return new SOARecordManager($db, $managerConfig, $provider);
    }
}
