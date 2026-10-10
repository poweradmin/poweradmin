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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Service\SessionAuthenticator;

#[CoversClass(SessionAuthenticator::class)]
final class SessionAuthenticatorGateTest extends TestCase
{
    private const MFA_SKIP_PATHS = ['/logout', '/mfa/verify', '/mfa/setup'];

    public static function paths(): array
    {
        return [
            'setup page' => ['/mfa/setup', SessionAuthenticator::GATE_SKIP],
            'setup sub page' => ['/mfa/setup/verify', SessionAuthenticator::GATE_SKIP],
            'logout' => ['/logout', SessionAuthenticator::GATE_SKIP],
            'public API v2' => ['/api/v2/zones', SessionAuthenticator::GATE_SKIP],
            'public API v1 root' => ['/api/v1', SessionAuthenticator::GATE_SKIP],
            'preferences every page loads' => ['/api/internal/user-preferences', SessionAuthenticator::GATE_SKIP],
            'internal zone API' => ['/api/internal/zone', SessionAuthenticator::GATE_JSON],
            'internal validation API' => ['/api/internal/validation', SessionAuthenticator::GATE_JSON],
            'internal DNS wizard API' => ['/api/internal/dns-wizard', SessionAuthenticator::GATE_JSON],
            'web page with api in its path' => ['/settings/api/logs', SessionAuthenticator::GATE_REDIRECT],
            'API key settings page' => ['/settings/api-keys', SessionAuthenticator::GATE_REDIRECT],
            'zone list' => ['/zones/forward', SessionAuthenticator::GATE_REDIRECT],
            'skip path prefix is not a match' => ['/mfa/setupx', SessionAuthenticator::GATE_REDIRECT],
        ];
    }

    public static function legacyPaths(): array
    {
        return [
            'setup page' => ['/mfa/setup', SessionAuthenticator::GATE_SKIP],
            'internal zone API' => ['/api/internal/zone', SessionAuthenticator::GATE_SKIP],
            'web page with api in its path' => ['/settings/api/logs', SessionAuthenticator::GATE_SKIP],
            'zone list' => ['/zones/forward', SessionAuthenticator::GATE_REDIRECT],
        ];
    }

    #[Test]
    #[DataProvider('legacyPaths')]
    public function withoutStrictGatesEveryPathWithApiIsSkippedAsBefore(string $path, string $expected): void
    {
        $this->assertSame($expected, SessionAuthenticator::gateAction($path, self::MFA_SKIP_PATHS, 'POST', false));
    }

    #[Test]
    public function preferenceWritesAreHeldBackLikeOtherInternalCalls(): void
    {
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $this->assertSame(
                SessionAuthenticator::GATE_JSON,
                SessionAuthenticator::gateAction('/api/internal/user-preferences', self::MFA_SKIP_PATHS, $method),
                $method
            );
        }
    }

    #[Test]
    #[DataProvider('paths')]
    public function gateActionClassifiesPath(string $path, string $expected): void
    {
        $this->assertSame($expected, SessionAuthenticator::gateAction($path, self::MFA_SKIP_PATHS));
    }
}
