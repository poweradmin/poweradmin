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

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Infrastructure\Service\MessageService;
use ReflectionClass;
use ReflectionMethod;

/**
 * A record comment past the 65535 bytes the column holds is refused before the record is
 * written, instead of failing after the record row is already committed.
 */
class RecordManagerCommentCapTest extends TestCase
{
    private function manager(int $expectedErrors): RecordManager
    {
        $messages = $this->createMock(MessageService::class);
        $messages->expects($this->exactly($expectedErrors))->method('addSystemError')
            ->with('The Comment field must be at most 65535 characters.');

        $reflection = new ReflectionClass(RecordManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('messageService')->setValue($manager, $messages);

        return $manager;
    }

    public function testAddRefusesAnOversizedCommentBeforeAnyWrite(): void
    {
        $manager = $this->manager(1);

        $id = $manager->addRecordGetId(1, 'www', 'A', '192.0.2.1', 300, 0, 0, ['content' => str_repeat('a', 65536), 'account' => 'u']);

        $this->assertNull($id);
    }

    public function testEditRefusesAnOversizedCommentBeforeAnyWrite(): void
    {
        $manager = $this->manager(1);

        $this->assertFalse($manager->editRecord(['rid' => 5], ['content' => str_repeat('a', 65536), 'account' => 'u']));
    }

    public function testTheCapCountsBytesNotCharacters(): void
    {
        $manager = $this->manager(1);

        $this->assertTrue($this->refused($manager, ['content' => str_repeat("\u{00e9}", 32768)]));
    }

    public function testACommentAtTheLimitOrAbsentIsNotRefused(): void
    {
        $manager = $this->manager(0);

        $this->assertFalse($this->refused($manager, ['content' => str_repeat('a', 65535)]));
        $this->assertFalse($this->refused($manager, null));
        $this->assertFalse($this->refused($manager, ['content' => '']));
    }

    private function refused(RecordManager $manager, ?array $comment): bool
    {
        return (new ReflectionMethod($manager, 'refuseOversizedComment'))->invoke($manager, $comment);
    }
}
