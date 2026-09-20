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

use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * OidcService::getCallbackUrl() and SamlConfigurationService::getBaseUrl()
     * both throw when no URL source is configured, because neither the redirect_uri
     * nor the advertised entityID may be derived from the request. Without this
     * check the container starts and only fails at login time.
     *
     * @param array<int, string> $expectedVariables
     * @dataProvider ssoValidatorProvider
     */
    public function testEntrypointRequiresAUrlSourceForSso(string $function, array $expectedVariables): void
    {
        $body = $this->validatorBody($function);

        foreach ($expectedVariables as $variable) {
            $this->assertStringContainsString(
                $variable,
                $body,
                sprintf('%s() must consider %s before refusing to start', $function, $variable)
            );
        }
    }

    /**
     * SAML resolves its SP URLs from application_url, the legacy base_url, or explicit
     * SP URLs, so gating startup on application_url alone broke working deployments.
     * OIDC has no such alternative - application_url is its only source.
     */
    public function testSamlValidatorAcceptsTheAlternativeUrlSources(): void
    {
        $body = $this->validatorBody('validate_saml_config');

        $this->assertStringContainsString('PA_BASE_URL', $body);
        $this->assertStringContainsString('PA_SAML_SP_ENTITY_ID', $body);
        $this->assertStringContainsString('PA_SAML_SP_ACS_URL', $body);
        $this->assertStringContainsString('PA_SAML_SP_SLS_URL', $body);
    }

    /**
     * The change approval switches ship off, and the entrypoint must both read
     * the PA_* variable with the same default and emit it into the matching
     * settings group.
     */
    #[DataProvider('approvalVariableProvider')]
    public function testEntrypointApprovalDefaultsMatchShippedDefaults(string $variable, string $group, string $key, string $shellVariable): void
    {
        $repoRoot = dirname(__DIR__, 2);

        $entrypoint = file_get_contents($repoRoot . '/docker-entrypoint.sh');
        $this->assertNotFalse($entrypoint, 'docker-entrypoint.sh could not be read');

        $defaults = require $repoRoot . '/config/settings.defaults.php';
        $this->assertArrayHasKey($key, $defaults[$group], sprintf('%s.%s missing from settings.defaults.php', $group, $key));
        $this->assertFalse($defaults[$group][$key], sprintf('%s.%s must ship disabled', $group, $key));

        $this->assertStringContainsString(
            sprintf('%s=$(to_php_bool "${%s:-false}")', $shellVariable, $variable),
            $entrypoint,
            sprintf('%s must default to false in docker-entrypoint.sh', $variable)
        );

        $this->assertStringContainsString(
            sprintf("'%s' => \${%s},", $key, $shellVariable),
            $this->settingsGroupBody($entrypoint, $group),
            sprintf('%s must be emitted as %s.%s in the generated settings.php', $variable, $group, $key)
        );
    }

    public static function approvalVariableProvider(): array
    {
        return [
            'approval enabled' => ['PA_APPROVAL_ENABLED', 'approval', 'enabled', 'approval_enabled'],
            'approval require review for all' => ['PA_APPROVAL_REQUIRE_REVIEW_FOR_ALL', 'approval', 'require_review_for_all', 'approval_require_review_for_all'],
            'change request notifications' => ['PA_NOTIFICATION_CHANGE_REQUEST', 'notifications', 'change_request_enabled', 'notification_change_request'],
            'change request SOA contact mail' => ['PA_NOTIFICATION_CHANGE_REQUEST_SOA_CONTACT', 'notifications', 'change_request_soa_contact', 'notification_change_request_soa_contact'],
        ];
    }

    private function settingsGroupBody(string $entrypoint, string $group): string
    {
        $start = strpos($entrypoint, sprintf("    '%s' => [\n", $group));
        $this->assertNotFalse($start, sprintf("'%s' group not found in the generated settings.php", $group));

        $end = strpos($entrypoint, "\n    ],\n", $start);
        $this->assertNotFalse($end, sprintf("'%s' group has no closing bracket", $group));

        return substr($entrypoint, $start, $end - $start);
    }

    private function validatorBody(string $function): string
    {
        $entrypoint = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        $this->assertNotFalse($entrypoint, 'docker-entrypoint.sh could not be read');

        $start = strpos($entrypoint, $function . '() {');
        $this->assertNotFalse($start, sprintf('%s() not found in docker-entrypoint.sh', $function));

        $end = strpos($entrypoint, "\n}\n", $start);
        $this->assertNotFalse($end, sprintf('%s() has no closing brace', $function));

        return substr($entrypoint, $start, $end - $start);
    }

    public static function ssoValidatorProvider(): array
    {
        return [
            'saml' => ['validate_saml_config', ['PA_APPLICATION_URL', 'PA_BASE_URL']],
            'oidc' => ['validate_oidc_config', ['PA_APPLICATION_URL']],
        ];
    }
}
