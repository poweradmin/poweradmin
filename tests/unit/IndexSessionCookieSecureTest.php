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


namespace unit;

use PHPUnit\Framework\TestCase;

/**
 * index.php is a script, so the Secure flag expression is checked by running it: it must follow
 * detected HTTPS (direct or via X-Forwarded-Proto) and must never lower php.ini's cookie_secure.
 */
class IndexSessionCookieSecureTest extends TestCase
{
    private function secureExpression(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/index.php');
        $this->assertNotFalse($source);
        $this->assertSame(1, preg_match('/^\$secure = (.+?);$/ms', $source, $m), '$secure assignment not found in index.php');

        return $m[1];
    }

    private function evaluate(array $server, string $iniValue): bool
    {
        $previousServer = $_SERVER;
        $previousIni = ini_get('session.cookie_secure');
        try {
            $_SERVER = $server;
            ini_set('session.cookie_secure', $iniValue);

            return (bool)eval('return ' . $this->secureExpression() . ';');
        } finally {
            $_SERVER = $previousServer;
            ini_set('session.cookie_secure', (string)$previousIni);
        }
    }

    public function testPlainHttpWithoutIniIsNotSecure(): void
    {
        $this->assertFalse($this->evaluate([], '0'));
        $this->assertFalse($this->evaluate(['HTTPS' => 'off'], '0'));
    }

    public function testDetectedHttpsIsSecure(): void
    {
        $this->assertTrue($this->evaluate(['HTTPS' => 'on'], '0'));
        $this->assertTrue($this->evaluate(['HTTP_X_FORWARDED_PROTO' => 'https'], '0'));
    }

    public function testIniCookieSecureIsNeverLowered(): void
    {
        $this->assertTrue($this->evaluate([], '1'));
    }
}
