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

namespace Poweradmin\Domain\Service\Auth;

use Poweradmin\Domain\Enum\SessionGateAction;

/**
 * Pure rules for which requests a session gate (required MFA setup, user agreement)
 * holds back while the user still has to complete it.
 */
final class SessionGatePolicy
{
    private const PREFERENCES_PATH = '/api/internal/user-preferences';

    /**
     * The key-authenticated public APIs carry no session and are skipped. The internal
     * API runs on the session, so in strict mode it is refused until the user complies,
     * except the preference read every page makes, the gate pages included. Strict
     * matching is on whole API path segments, so a web page such as /settings/api/logs
     * is still redirected. Without strict mode every path containing /api/ is skipped,
     * as before 4.6.0.
     *
     * @param string $path Request path without base_url_prefix
     * @param string[] $gatePaths Pages the gate itself sends the user to
     */
    public static function decide(string $path, array $gatePaths, string $method, bool $strict): SessionGateAction
    {
        foreach ($gatePaths as $gatePath) {
            if ($path === $gatePath || str_starts_with($path, $gatePath . '/')) {
                return SessionGateAction::SKIP;
            }
        }

        if (!$strict) {
            return str_contains($path, '/api/') ? SessionGateAction::SKIP : SessionGateAction::REDIRECT;
        }

        if (preg_match('#/api/v\d+(/|$)#', $path) === 1) {
            return SessionGateAction::SKIP;
        }

        if (preg_match('#/api/internal(/|$)#', $path) === 1) {
            return strtoupper($method) === 'GET' && str_ends_with(rtrim($path, '/'), self::PREFERENCES_PATH)
                ? SessionGateAction::SKIP
                : SessionGateAction::REJECT;
        }

        return SessionGateAction::REDIRECT;
    }
}
