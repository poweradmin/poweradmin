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

declare(strict_types=1);

namespace unit\Infrastructure\Session;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Session\ArraySession;
use Poweradmin\Infrastructure\Session\PhpSession;

/**
 * The adapter must keep writing the same $_SESSION keys it replaced, and the
 * in-memory one must answer identically so a test can stand in for it.
 */
class PhpSessionTest extends TestCase
{
    private array $backup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->backup;
        parent::tearDown();
    }

    public function testValuesLandInTheSuperglobalUnderTheKeyGiven(): void
    {
        $session = new PhpSession();
        $session->set('userid', 7);

        $this->assertSame(7, $_SESSION['userid']);
        $this->assertSame(7, $session->get('userid'));
        $this->assertTrue($session->has('userid'));
        $this->assertSame(['userid' => 7], $session->all());

        $session->remove('userid');
        $this->assertArrayNotHasKey('userid', $_SESSION);
        $this->assertFalse($session->has('userid'));
    }

    public function testAMissingKeyReadsAsTheGivenDefault(): void
    {
        $session = new PhpSession();

        $this->assertNull($session->get('nothing'));
        $this->assertSame('fallback', $session->get('nothing', 'fallback'));
    }

    /**
     * A null value is stored, but isset() semantics report it as absent; the
     * readers this replaced used isset() and ?? and must keep that behaviour.
     */
    public function testANullValueCountsAsAbsent(): void
    {
        $session = new PhpSession();
        $session->set('maybe', null);

        $this->assertFalse($session->has('maybe'));
        $this->assertSame('fallback', $session->get('maybe', 'fallback'));
    }

    public function testClearDropsEveryValueWithoutASessionOpen(): void
    {
        $session = new PhpSession();
        $session->set('a', 1);
        $session->set('b', 2);

        $session->clear();

        $this->assertSame([], $session->all());
    }

    #[DataProvider('sessions')]
    public function testBothAdaptersAnswerTheSame(string $class): void
    {
        /** @var PhpSession|ArraySession $session */
        $session = new $class();

        $this->assertFalse($session->has('k'));
        $this->assertNull($session->get('k'));

        $session->set('k', ['nested' => true]);
        $this->assertSame(['nested' => true], $session->get('k'));
        $this->assertTrue($session->has('k'));

        $session->remove('k');
        $this->assertFalse($session->has('k'));

        $session->set('k', 'v');
        $session->clear();
        $this->assertSame([], $session->all());
    }

    public static function sessions(): array
    {
        return [PhpSession::class => [PhpSession::class], ArraySession::class => [ArraySession::class]];
    }
}
