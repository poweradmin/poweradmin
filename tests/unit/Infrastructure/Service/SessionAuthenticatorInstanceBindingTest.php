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

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\SessionAuthenticator;

#[CoversClass(SessionAuthenticator::class)]
final class SessionAuthenticatorInstanceBindingTest extends TestCase
{
    private function config(array $overrides = []): ConfigurationManager
    {
        $values = array_merge([
            'database.host' => 'db1',
            'database.port' => '3306',
            'database.name' => 'pa',
            'database.type' => 'mysql',
            'interface.base_url_prefix' => '',
            'security.session_key' => 'key-one',
        ], $overrides);

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null) => $values["$group.$key"] ?? $default
        );

        return $config;
    }

    #[Test]
    public function matchingFingerprintPasses(): void
    {
        $fp = SessionAuthenticator::instanceFingerprint($this->config());

        $this->assertTrue(SessionAuthenticator::sessionBelongsToInstance($fp, SessionAuthenticator::instanceFingerprint($this->config())));
    }

    #[Test]
    public function missingFingerprintIsRefused(): void
    {
        $fp = SessionAuthenticator::instanceFingerprint($this->config());

        $this->assertFalse(SessionAuthenticator::sessionBelongsToInstance(null, $fp));
        $this->assertFalse(SessionAuthenticator::sessionBelongsToInstance('', $fp));
    }

    #[Test]
    public function anotherInstancesFingerprintIsRefused(): void
    {
        $mine = SessionAuthenticator::instanceFingerprint($this->config());

        $others = [
            ['database.name' => 'staging'],
            ['database.file' => '/other.db'],
            ['database.host' => 'db2'],
            ['database.port' => '3307'],
            ['database.type' => 'pgsql'],
            ['interface.base_url_prefix' => '/other'],
            ['security.session_key' => 'key-two'],
        ];

        foreach ($others as $override) {
            $theirs = SessionAuthenticator::instanceFingerprint($this->config($override));
            $this->assertFalse(SessionAuthenticator::sessionBelongsToInstance($theirs, $mine), json_encode($override));
        }
    }

    #[Test]
    public function onlySessionsHoldingAUserIdAreJudged(): void
    {
        $fp = SessionAuthenticator::instanceFingerprint($this->config());
        $key = SessionAuthenticator::SSO_INSTANCE_KEY;

        $this->assertTrue(SessionAuthenticator::isForeignSsoSession(['userid' => 1], $fp));
        $this->assertTrue(SessionAuthenticator::isForeignSsoSession(['pending_userid' => 1, $key => 'other'], $fp));
        $this->assertFalse(SessionAuthenticator::isForeignSsoSession(['userid' => 1, $key => $fp], $fp));
        $this->assertFalse(SessionAuthenticator::isForeignSsoSession(['pending_userid' => 1, $key => $fp], $fp));
        $this->assertFalse(SessionAuthenticator::isForeignSsoSession(['userlogin' => 'x'], $fp));
    }

    #[Test]
    public function everySsoSignInRecordsTheFingerprint(): void
    {
        $root = dirname(__DIR__, 4) . '/lib/Application/Service/';

        // Pending (MFA) and final sign-in each set it; the key is shared, so MFA promotion keeps it
        $this->assertSame(2, substr_count((string)file_get_contents($root . 'OidcService.php'), 'SessionAuthenticator::SSO_INSTANCE_KEY'));
        $this->assertSame(2, substr_count((string)file_get_contents($root . 'SamlService.php'), 'SessionAuthenticator::SSO_INSTANCE_KEY'));
    }
}
