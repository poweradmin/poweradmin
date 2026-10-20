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

namespace Poweradmin\Tests\Unit\Domain\Service\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Enum\SessionGateAction;
use Poweradmin\Domain\Service\Auth\SessionGatePolicy;

#[CoversClass(SessionGatePolicy::class)]
final class SessionGatePolicyTest extends TestCase
{
    private const MFA_SKIP_PATHS = ['/logout', '/mfa/verify', '/mfa/setup'];

    public static function paths(): array
    {
        return [
            'setup page' => ['/mfa/setup', SessionGateAction::SKIP],
            'setup sub page' => ['/mfa/setup/verify', SessionGateAction::SKIP],
            'logout' => ['/logout', SessionGateAction::SKIP],
            'public API v2' => ['/api/v2/zones', SessionGateAction::SKIP],
            'public API v1 root' => ['/api/v1', SessionGateAction::SKIP],
            'preferences every page loads' => ['/api/internal/user-preferences', SessionGateAction::SKIP],
            'internal zone API' => ['/api/internal/zone', SessionGateAction::REJECT],
            'internal validation API' => ['/api/internal/validation', SessionGateAction::REJECT],
            'internal DNS wizard API' => ['/api/internal/dns-wizard', SessionGateAction::REJECT],
            'web page with api in its path' => ['/settings/api/logs', SessionGateAction::REDIRECT],
            'API key settings page' => ['/settings/api-keys', SessionGateAction::REDIRECT],
            'zone list' => ['/zones/forward', SessionGateAction::REDIRECT],
            'skip path prefix is not a match' => ['/mfa/setupx', SessionGateAction::REDIRECT],
        ];
    }

    public static function legacyPaths(): array
    {
        return [
            'setup page' => ['/mfa/setup', SessionGateAction::SKIP],
            'internal zone API' => ['/api/internal/zone', SessionGateAction::SKIP],
            'web page with api in its path' => ['/settings/api/logs', SessionGateAction::SKIP],
            'zone list' => ['/zones/forward', SessionGateAction::REDIRECT],
        ];
    }

    #[Test]
    #[DataProvider('legacyPaths')]
    public function withoutStrictGatesEveryPathWithApiIsSkippedAsBefore(string $path, SessionGateAction $expected): void
    {
        $this->assertSame($expected, SessionGatePolicy::decide($path, self::MFA_SKIP_PATHS, 'POST', false));
    }

    #[Test]
    public function preferenceWritesAreHeldBackLikeOtherInternalCalls(): void
    {
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $this->assertSame(
                SessionGateAction::REJECT,
                SessionGatePolicy::decide('/api/internal/user-preferences', self::MFA_SKIP_PATHS, $method, true),
                $method
            );
        }
    }

    #[Test]
    #[DataProvider('paths')]
    public function decideClassifiesPath(string $path, SessionGateAction $expected): void
    {
        $this->assertSame($expected, SessionGatePolicy::decide($path, self::MFA_SKIP_PATHS, 'GET', true));
    }
}
