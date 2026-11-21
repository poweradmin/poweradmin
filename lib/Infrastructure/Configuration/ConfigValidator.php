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

namespace Poweradmin\Infrastructure\Configuration;

use Poweradmin\Infrastructure\Database\DbDriver;

/**
 * Checks the loaded settings array for invalid values (syslog, session timeout, theme, PowerDNS API, pdns_db_name).
 */
final class ConfigValidator
{
    private array $config;
    private array $errors;
    private array $warnings;

    /**
     * Toggles read by plain truthiness, where a quoted 'false' counts as on. A fixed list;
     * other booleans are not checked.
     */
    private const BOOLEAN_SETTINGS = [
        'database.debug', 'database.ssl', 'database.ssl_verify',
        'security.login_token_validation', 'security.global_token_validation',
        'security.protect_last_admin_on_edit', 'security.strict_session_gates',
        'security.account_lockout.enable_lockout', 'security.account_lockout.track_ip_address',
        'security.account_lockout.clear_attempts_on_success',
        'security.mfa.enabled', 'security.mfa.enforced', 'security.mfa.skip_for_external_auth',
        'security.password_reset.enabled', 'security.username_recovery.enabled', 'security.recaptcha.enabled',
        'interface.web_enabled', 'dns.strict_tld_check', 'dns.parent_zone_ownership_check',
        'mail.enabled', 'approval.enabled', 'approval.allow_self_approval', 'dnssec.enabled',
        'logging.database_enabled', 'misc.display_errors',
        'ldap.enabled', 'ldap.debug', 'ldap.auto_provision', 'ldap.allow_superuser_provisioning',
        'api.enabled', 'api.basic_auth_enabled', 'api.docs_enabled',
        'health.enabled', 'health.ping_enabled', 'metrics.enabled', 'metrics.require_auth',
        'user_agreement.enabled',
        'oidc.enabled', 'oidc.auto_provision', 'oidc.link_by_email', 'oidc.require_verified_email', 'oidc.allow_superuser_provisioning',
        'saml.enabled', 'saml.auto_provision', 'saml.link_by_email', 'saml.allow_superuser_provisioning',
        'remote_user.enabled', 'remote_user.hide_login_form', 'remote_user.auto_provision', 'remote_user.allow_superuser_provisioning',
    ];

    /** Keys the code still reads although the defaults dropped them, with their replacement. */
    private const LEGACY_KEYS = [
        'interface.base_url' => 'interface.application_url',
        'misc.email_previews_enabled' => 'modules.email_previews.enabled',
    ];

    /** Read by the code but undocumented and absent from the defaults, so never reported as unknown. */
    private const UNDOCUMENTED_KEYS = ['dns.domain'];

    /** @var array<string, mixed>|null the shipped defaults tree, for spotting keys it does not know */
    private ?array $defaults;

    /**
     * @param array<string, mixed> $config merged settings
     * @param array<string, mixed>|null $defaults settings.defaults.php; without it unknown keys are not reported
     */
    public function __construct(array $config, ?array $defaults = null)
    {
        $this->defaults = $defaults;
        $this->config = $config;
        $this->errors = [];
        $this->warnings = [];
    }

    public function validate(): bool
    {
        $this->errors = [];
        $this->warnings = [];

        $this->validateIfaceRowAmount();
        $this->validateSessionTimeout();
        $this->validatePasswordEncryption();
        $this->validateIfaceLang();
        $this->validateTheme();
        $this->validateSyslogUse();
        if ($this->getSetting('logging', 'syslog_enabled')) {
            $this->validateSyslogIdent();
            $this->validateSyslogFacility();
        }
        $this->validatePdnsApiUrl();
        $this->validatePdnsApiKey();
        $this->validatePdnsApiTimeout();
        $this->validateDnsBackend();
        $this->validatePdnsDbName();
        $this->validatePermissions();
        $this->validateApproval();
        $this->validateZoneLimitDefaults();
        $this->warnOnNonBooleanToggles();
        $this->warnOnUnknownKeys();

        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Settings that are legal but ineffective; they never fail validate().
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Helper method to get setting from hierarchical config
     *
     * @param string $group Settings group
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    private function getSetting(string $group, string $key, mixed $default = null): mixed
    {
        return $this->config[$group][$key] ?? $default;
    }

    private function warnOnNonBooleanToggles(): void
    {
        foreach (self::BOOLEAN_SETTINGS as $path) {
            $value = $this->config;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    continue 2;
                }
                $value = $value[$segment];
            }

            if (!is_bool($value)) {
                $this->warnings[$path] = sprintf('%s should be an unquoted true or false; %s is read as %s', $path, var_export($value, true), $value ? 'on' : 'off');
            }
        }
    }

    /**
     * A misspelt key is ignored and the default applies instead. Where the defaults hold a
     * list or an empty array the contents are free-form (providers, mappings, claim and
     * attribute maps, TLD and proxy lists) and are not walked. Unknown module names under
     * modules are left alone because add-on modules keep their own settings there.
     */
    private function warnOnUnknownKeys(): void
    {
        if ($this->defaults === null) {
            return;
        }

        $this->collectUnknownKeys($this->config, $this->defaults, '');
    }

    /**
     * @param array<mixed> $config
     * @param array<mixed> $defaults
     */
    private function collectUnknownKeys(array $config, array $defaults, string $prefix): void
    {
        foreach ($config as $key => $value) {
            $path = $prefix . $key;
            if (!array_key_exists($key, $defaults)) {
                $this->reportAbsentKey($path, $value);
                continue;
            }

            $known = $defaults[$key];
            if (is_array($value) && is_array($known) && !array_is_list($known)) {
                $this->collectUnknownKeys($value, $known, $path . '.');
            }
        }
    }

    private function reportAbsentKey(string $path, mixed $value): void
    {
        // modules.<name> with an unknown name is an add-on module; keys inside a known one are checked
        $isAddOnModule = str_starts_with($path, 'modules.') && substr_count($path, '.') === 1;
        if ($isAddOnModule || in_array($path, self::UNDOCUMENTED_KEYS, true)) {
            return;
        }

        $replacement = self::LEGACY_KEYS[$path] ?? null;
        if ($replacement === null && !str_contains($path, '.') && isset($this->defaults['modules'][$path]) && is_array($value)) {
            $replacement = 'modules.' . $path;
        }

        if ($replacement === null) {
            $this->warnings[$path] = sprintf('%s is not a known setting and is ignored; check the spelling', $path);
        } elseif ($value !== '' && $value !== null) {
            // An empty value is what the Docker image writes for an unset variable
            $this->warnings[$path] = sprintf('%s is deprecated, use %s', $path, $replacement);
        }
    }

    private function validateSyslogUse(): void
    {
        $syslogEnabled = $this->getSetting('logging', 'syslog_enabled');
        if (!is_bool($syslogEnabled)) {
            $this->errors['logging.syslog_enabled'] = 'syslog_enabled must be a boolean value (unquoted true or false)';
        }
    }

    private function validateSyslogIdent(): void
    {
        $syslogIdent = $this->getSetting('logging', 'syslog_identity');
        if (!is_string($syslogIdent) || empty($syslogIdent)) {
            $this->errors['logging.syslog_identity'] = 'syslog_identity must be a non-empty string';
        }
    }

    private function validateSyslogFacility(): void
    {
        $validFacilities = [
            'LOG_USER' => LOG_USER,
            'LOG_LOCAL0' => LOG_LOCAL0,
            'LOG_LOCAL1' => LOG_LOCAL1,
            'LOG_LOCAL2' => LOG_LOCAL2,
            'LOG_LOCAL3' => LOG_LOCAL3,
            'LOG_LOCAL4' => LOG_LOCAL4,
            'LOG_LOCAL5' => LOG_LOCAL5,
            'LOG_LOCAL6' => LOG_LOCAL6,
            'LOG_LOCAL7' => LOG_LOCAL7,
        ];

        $syslogFacility = $this->getSetting('logging', 'syslog_facility');
        if (!in_array($syslogFacility, $validFacilities)) {
            $validFacilitiesList = implode(', ', array_keys($validFacilities));
            $this->errors['logging.syslog_facility'] = "syslog_facility must be an unquoted value and one of the following values: $validFacilitiesList";
        }
    }

    private function validateIfaceRowAmount(): void
    {
        $rowsPerPage = $this->getSetting('interface', 'rows_per_page');
        if (!is_int($rowsPerPage) || $rowsPerPage <= 0) {
            $this->errors['interface.rows_per_page'] = 'rows_per_page must be a positive integer';
        }
    }

    private function validateZoneLimitDefaults(): void
    {
        foreach (['default_max_zones_per_user', 'default_max_zones_per_group'] as $key) {
            $value = $this->getSetting('dns', $key);
            if ($value !== null && (!is_int($value) || $value < 0)) {
                $this->errors['dns.' . $key] = $key . ' must be null (unlimited) or a whole number of 0 or more';
            }
        }
    }

    private function validateSessionTimeout(): void
    {
        // 0 is not "no timeout": the expiry check would log every request out
        $sessionTimeout = $this->getSetting('interface', 'session_timeout', 1800);
        if (!is_int($sessionTimeout) || $sessionTimeout <= 0) {
            $this->errors['interface.session_timeout'] = 'session_timeout must be a positive integer (seconds); the timeout cannot be disabled';
        }

        $maxLifetime = $this->getSetting('interface', 'session_max_lifetime', 0);
        if (!is_int($maxLifetime) || $maxLifetime < 0) {
            $this->errors['interface.session_max_lifetime'] = 'session_max_lifetime must be 0 (off) or a positive integer (seconds)';
        }

        $bulkMaxOperations = $this->getSetting('api', 'bulk_max_operations', 1000);
        if (!is_int($bulkMaxOperations) || $bulkMaxOperations < 0) {
            $this->errors['api.bulk_max_operations'] = 'bulk_max_operations must be 0 (no limit) or a positive integer';
        }
    }

    /**
     * md5 and md5salt were removed in 4.3.0; an old settings.php still naming them would
     * make every password hash or rehash throw, so fail at boot with a readable message.
     */
    private function validatePasswordEncryption(): void
    {
        $method = $this->getSetting('security', 'password_encryption', 'bcrypt');
        if (!in_array($method, ['bcrypt', 'argon2i', 'argon2id'], true)) {
            $this->errors['security.password_encryption'] = sprintf(
                "password_encryption '%s' is not supported (md5 and md5salt were removed in 4.3.0); use bcrypt, argon2i or argon2id. "
                . 'Existing md5 password hashes still verify and are rehashed at the next login',
                is_scalar($method) ? (string)$method : get_debug_type($method)
            );
            return;
        }

        $policy = $this->getSetting('security', 'password_policy', []);
        $minLength = is_array($policy) ? ($policy['min_length'] ?? 0) : 0;
        if ($method === 'bcrypt' && !empty($policy['enable_password_rules']) && is_numeric($minLength) && (int)$minLength > 72) {
            $this->warnings['security.password_policy.min_length'] = sprintf(
                'password_policy.min_length %d is longer than bcrypt accepts (72 bytes), so no password can pass; lower it or use argon2id',
                (int)$minLength
            );
        }
    }

    private function validateIfaceLang(): void
    {
        $language = $this->getSetting('interface', 'language');
        if (!is_string($language) || empty($language)) {
            $this->errors['interface.language'] = 'language must be a non-empty string';
        }

        $enabledLanguages = $this->getSetting('interface', 'enabled_languages');
        if (empty($enabledLanguages)) {
            $this->errors['interface.enabled_languages'] = 'enabled_languages must be a non-empty string and contain a list of languages separated by commas';
            return;
        }

        $enabledLanguagesArray = array_map('trim', explode(',', $enabledLanguages));
        if (!in_array($language, $enabledLanguagesArray)) {
            $this->errors['interface.language'] = 'language must be one of the enabled languages';
        }

        foreach ($enabledLanguagesArray as $lang) {
            if (empty($lang)) {
                $this->errors['interface.enabled_languages'] = 'enabled_languages must be a non-empty string and contain a list of languages separated by commas';
                break;
            }
        }
    }

    private function validateTheme(): void
    {
        $themeBasePath = $this->getSetting('interface', 'theme_base_path', 'templates');

        if (!is_string($themeBasePath) || $themeBasePath === '') {
            $this->errors['interface.theme_base_path'] = 'theme_base_path must be a non-empty string';
            return;
        }

        // Resolve against the app root (not the process cwd) so the theme-exists
        // check doesn't spuriously fail when invoked from another directory.
        $themeBasePath = ThemePathResolver::toFilesystemPath($themeBasePath);
        $theme = $this->getSetting('interface', 'theme', 'default');

        if (!is_string($theme) || empty($theme)) {
            $this->errors['interface.theme'] = 'theme must be a non-empty string';
            return;
        }

        $themePath = $themeBasePath . '/' . $theme;

        if (!is_dir($themePath)) {
            // Check if this is a legacy config issue (old removed themes)
            $removedThemes = ['spark', 'ignite', 'mobile'];
            if (in_array($theme, $removedThemes)) {
                $this->errors['interface.theme'] = "Theme '$theme' was removed in Poweradmin 4.0. The theme directory '$themePath' does not exist. " .
                    "Please update your configuration to use theme: 'default'.";
            } else {
                $this->errors['interface.theme'] = "Theme directory '$themePath' does not exist. " .
                    "Please check your theme configuration or use the default theme.";
            }
        }
    }

    /**
     * When dns.backend is "api", both pdns_api.url and pdns_api.key must be set.
     * DnsBackendProviderFactory throws on this misconfiguration at runtime;
     * surfacing it here lets the install flow and config diagnostics catch it
     * before the first DNS operation.
     */
    private function validateDnsBackend(): void
    {
        $backend = $this->getSetting('dns', 'backend');
        if ($backend !== 'api') {
            return;
        }

        $apiUrl = $this->getSetting('pdns_api', 'url');
        $apiKey = $this->getSetting('pdns_api', 'key');

        if (($apiUrl === null || $apiUrl === '') && !isset($this->errors['pdns_api.url'])) {
            $this->errors['pdns_api.url'] = 'PowerDNS API URL is required when dns.backend is "api"';
        }

        if ((!is_string($apiKey) || $apiKey === '') && !isset($this->errors['pdns_api.key'])) {
            $this->errors['pdns_api.key'] = 'PowerDNS API key is required when dns.backend is "api"';
        }
    }

    private function validatePdnsApiUrl(): void
    {
        $apiUrl = $this->getSetting('pdns_api', 'url');

        // Skip validation if API URL is not configured
        if ($apiUrl === null || $apiUrl === '') {
            return;
        }

        // Check if it's a string
        if (!is_string($apiUrl)) {
            $this->errors['pdns_api.url'] = 'PowerDNS API URL must be a string';
            return;
        }

        // Check for missing protocol prefix - common misconfiguration
        if (!preg_match('/^https?:\/\//i', $apiUrl)) {
            $this->errors['pdns_api.url'] = 'PowerDNS API URL must start with http:// or https:// (found: "' . $apiUrl . '"). ' .
                'Common mistake: missing protocol prefix. Example: http://127.0.0.1:8081';
            return;
        }

        // Validate URL format
        $parsedUrl = parse_url($apiUrl);
        if ($parsedUrl === false || !isset($parsedUrl['scheme']) || !isset($parsedUrl['host'])) {
            $this->errors['pdns_api.url'] = 'PowerDNS API URL is not a valid URL format. Expected format: http://hostname:port or https://hostname:port';
            return;
        }

        // Check scheme
        if (!in_array(strtolower($parsedUrl['scheme']), ['http', 'https'])) {
            $this->errors['pdns_api.url'] = 'PowerDNS API URL must use http or https protocol';
            return;
        }

        // Check port if specified
        if (isset($parsedUrl['port'])) {
            // parse_url() already constrains the port to 0-65535
            $port = $parsedUrl['port'];

            if ($port < 1) {
                $this->errors['pdns_api.url'] = 'PowerDNS API URL port must be between 1 and 65535';
                return;
            }
        }

        if (strtolower($parsedUrl['scheme']) === 'http' && self::isRemoteHost($parsedUrl['host'])) {
            $this->warnings['pdns_api.url'] = 'PowerDNS API URL uses plain http to ' . $parsedUrl['host']
                . '; the API key is sent unencrypted. Use https, or reach the API over loopback or a private network';
        }
    }

    /**
     * A public address, or a dotted name; loopback, private and CGNAT addresses (Tailscale),
     * single-label container service names, *.localhost and *.internal (host.docker.internal)
     * are treated as local.
     */
    private static function isRemoteHost(string $host): bool
    {
        $host = rtrim(strtolower(trim($host, '[]')), '.');
        if (str_starts_with($host, '::ffff:') && filter_var(substr($host, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $host = substr($host, 7);
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && (ip2long($host) & 0xFFC00000) === ip2long('100.64.0.0')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return str_contains($host, '.') && $host !== 'localhost'
            && !str_ends_with($host, '.localhost') && !str_ends_with($host, '.internal');
    }

    private function validatePdnsApiKey(): void
    {
        $apiKey = $this->getSetting('pdns_api', 'key');
        $apiUrl = $this->getSetting('pdns_api', 'url');

        // Only validate API key if URL is configured
        if ($apiUrl === null || $apiUrl === '') {
            return;
        }

        if (!is_string($apiKey) || empty($apiKey)) {
            $this->errors['pdns_api.key'] = 'PowerDNS API key must be a non-empty string when API URL is configured';
        }
    }

    private function validatePdnsApiTimeout(): void
    {
        $timeout = $this->getSetting('pdns_api', 'timeout');

        // Absent or default - nothing to validate.
        if ($timeout === null) {
            return;
        }

        if (!is_int($timeout) && !(is_string($timeout) && ctype_digit($timeout))) {
            $this->errors['pdns_api.timeout'] = 'PowerDNS API timeout must be a positive integer (seconds)';
            return;
        }

        if ((int) $timeout < 1) {
            $this->errors['pdns_api.timeout'] = 'PowerDNS API timeout must be at least 1 second';
        }
    }

    /**
     * At least one permission template type (user or group) must remain visible
     * in the UI so admins can manage access. Both toggles control UI visibility
     * only; backend permission resolution always evaluates both types.
     */
    private function validatePermissions(): void
    {
        $showUser = $this->getSetting('permissions', 'show_user_access_templates', true);
        $showGroup = $this->getSetting('permissions', 'show_group_access_templates', true);

        if (!$showUser && !$showGroup) {
            $this->errors['permissions'] = 'At least one of show_user_access_templates or show_group_access_templates must be enabled';
        }
    }

    /**
     * require_review_for_all and allow_self_approval only take effect once the approval workflow is on.
     */
    private function validateApproval(): void
    {
        $enabled = $this->getSetting('approval', 'enabled', false);
        $requireReviewForAll = $this->getSetting('approval', 'require_review_for_all', false);

        if ($requireReviewForAll && !$enabled) {
            $this->warnings['approval.require_review_for_all'] = 'approval.require_review_for_all has no effect while approval.enabled is false';
        }
        if (!$this->getSetting('approval', 'allow_self_approval', true) && !$enabled) {
            $this->warnings['approval.allow_self_approval'] = 'approval.allow_self_approval has no effect while approval.enabled is false';
        }
    }

    private function validatePdnsDbName(): void
    {
        $dbType = $this->getSetting('database', 'type');
        $pdnsDbName = $this->getSetting('database', 'pdns_db_name');

        // Skip if pdns_db_name is not set or is null (which is valid)
        if ($pdnsDbName === null) {
            return;
        }

        // For PostgreSQL and SQLite, pdns_db_name should be null or empty
        // Only MySQL supports separate database for PowerDNS tables
        if (DbDriver::tryFrom((string)$dbType)?->supportsSeparatePdnsDb() === false && !empty($pdnsDbName)) {
            $this->errors['database.pdns_db_name'] = "pdns_db_name must be null for '$dbType' (only MySQL supports separate database)";
        }
    }
}
