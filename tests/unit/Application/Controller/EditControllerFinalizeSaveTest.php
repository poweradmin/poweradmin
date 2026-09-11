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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\EditController;
use Poweradmin\Domain\Enum\ZoneSaveOutcome;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;
use ReflectionClass;

/**
 * A zone save that changes nothing still bumps the SOA serial by default, because
 * that is how operators force a NOTIFY (#762). dns.bump_serial_on_unchanged_save
 * lets an install opt out, and a save that did change records must bump either way.
 */
class EditControllerFinalizeSaveTest extends TestCase
{
    private array $configBackup = [];
    private bool $configInitializedBackup = false;
    private array $sessionBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$settings, $initialized] = $this->configProperties();
        $config = ConfigurationManager::getInstance();
        $this->configBackup = $settings->getValue($config);
        $this->configInitializedBackup = $initialized->getValue($config);
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        [$settings, $initialized] = $this->configProperties();
        $config = ConfigurationManager::getInstance();
        $settings->setValue($config, $this->configBackup);
        $initialized->setValue($config, $this->configInitializedBackup);
        $_SESSION = $this->sessionBackup;
        parent::tearDown();
    }

    #[Test]
    public function testUnchangedSaveBumpsSerialByDefault(): void
    {
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->once())->method('updateSOASerial')->with(42);

        $messages = $this->finalize($soa, ZoneSaveOutcome::NO_CHANGES, []);

        $this->assertSame('info', $messages[0]['type']);
        $this->assertStringContainsString('SOA serial was incremented', $messages[0]['content']);
    }

    #[Test]
    public function testUnchangedSaveLeavesSerialAloneWhenDisabled(): void
    {
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->never())->method('updateSOASerial');

        $messages = $this->finalize($soa, ZoneSaveOutcome::NO_CHANGES, ['bump_serial_on_unchanged_save' => false]);

        $this->assertSame('info', $messages[0]['type']);
        $this->assertSame('Zone saved successfully. No record changes were made.', $messages[0]['content']);
    }

    #[Test]
    public function testChangedSaveBumpsSerialEvenWhenDisabled(): void
    {
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->once())->method('updateSOASerial')->with(42);

        $messages = $this->finalize($soa, ZoneSaveOutcome::UPDATED, ['bump_serial_on_unchanged_save' => false]);

        $this->assertSame('success', $messages[0]['type']);
    }

    #[Test]
    public function testRefusedSaveNeverBumpsSerial(): void
    {
        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->expects($this->never())->method('updateSOASerial');

        $messages = $this->finalize($soa, ZoneSaveOutcome::SERIAL_CONFLICT, []);

        $this->assertSame('warning', $messages[0]['type']);
    }

    /**
     * @param array<string, mixed> $dnsOverrides
     * @return array<int, array{type: string, content: string}>
     */
    private function finalize(SOARecordManagerInterface $soa, ZoneSaveOutcome $outcome, array $dnsOverrides): array
    {
        $reflection = new ReflectionClass(EditController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $property = $reflection->getProperty('soaRecordManager');
        $property->setAccessible(true);
        $property->setValue($controller, $soa);

        [$settings, $initialized] = $this->configProperties();
        $config = ConfigurationManager::getInstance();
        $settings->setValue($config, [
            'dns' => $dnsOverrides,
            'dnssec' => ['enabled' => false],
        ]);
        $initialized->setValue($config, true);

        $base = new ReflectionClass($reflection->getParentClass()->getName());
        $configProperty = $base->getProperty('config');
        $configProperty->setAccessible(true);
        $configProperty->setValue($controller, $config);
        $messageService = new MessageService();
        $messageProperty = $base->getProperty('messageService');
        $messageProperty->setAccessible(true);
        $messageProperty->setValue($controller, $messageService);

        $controller->finalizeSave($outcome, 42, 'example.com');

        return $messageService->getMessages('edit') ?? [];
    }

    /** @return array{0: \ReflectionProperty, 1: \ReflectionProperty} */
    private function configProperties(): array
    {
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $settings = $reflection->getProperty('settings');
        $settings->setAccessible(true);
        $initialized = $reflection->getProperty('initialized');
        $initialized->setAccessible(true);

        return [$settings, $initialized];
    }
}
