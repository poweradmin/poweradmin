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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Docker entrypoint writes config/settings.php, which is merged over
 * settings.defaults.php. The merge overwrites scalars unconditionally, so an
 * empty value emitted here destroys the shipped default rather than deferring
 * to it.
 *
 * This bit the OIDC and SAML permission templates: both shipped as '' when the
 * entrypoint was written, both were later changed to 'Guest', and the
 * entrypoint was not updated. Auto-provisioning then aborted for any SSO user
 * who matched no group mapping, because an empty template name is treated as
 * unconfigured.
 */
class DockerEntrypointConfigDefaultsTest extends TestCase
{
    private const PROVIDER_CONFIG_GROUP = [
        'LDAP' => 'ldap',
        'OIDC' => 'oidc',
        'SAML' => 'saml',
    ];

    public function testEntrypointPermissionTemplateDefaultsMatchShippedDefaults(): void
    {
        $repoRoot = dirname(__DIR__, 2);

        $entrypoint = file_get_contents($repoRoot . '/docker-entrypoint.sh');
        $this->assertNotFalse($entrypoint, 'docker-entrypoint.sh could not be read');

        $defaults = require $repoRoot . '/config/settings.defaults.php';

        $matched = preg_match_all(
            '/\$\{PA_(?<provider>[A-Z]+)_DEFAULT_PERMISSION_TEMPLATE:-(?<default>[^}]*)\}/',
            $entrypoint,
            $matches,
            PREG_SET_ORDER
        );

        // Guards against the regex silently stopping to match, which would make
        // every assertion below vacuous.
        $this->assertNotEmpty($matched, 'No PA_*_DEFAULT_PERMISSION_TEMPLATE emissions found in docker-entrypoint.sh');

        foreach ($matches as $match) {
            $provider = $match['provider'];
            $shellDefault = $match['default'];

            $this->assertArrayHasKey(
                $provider,
                self::PROVIDER_CONFIG_GROUP,
                sprintf('Unknown provider %s; add it to PROVIDER_CONFIG_GROUP', $provider)
            );

            $group = self::PROVIDER_CONFIG_GROUP[$provider];
            $shipped = $defaults[$group]['default_permission_template'] ?? null;

            $this->assertNotNull($shipped, sprintf('%s.default_permission_template missing from settings.defaults.php', $group));

            $this->assertSame(
                $shipped,
                $shellDefault,
                sprintf(
                    'PA_%s_DEFAULT_PERMISSION_TEMPLATE defaults to "%s" but settings.defaults.php ships "%s". '
                    . 'The generated settings.php overrides the shipped default, so these must agree.',
                    $provider,
                    $shellDefault,
                    $shipped
                )
            );

            // An empty name is treated as unconfigured and aborts provisioning.
            $this->assertNotSame('', $shellDefault, sprintf('PA_%s_DEFAULT_PERMISSION_TEMPLATE must not default to an empty value', $provider));
        }
    }
}
