<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

class ConfigValidator
{
    private array $config;
    private array $errors;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->errors = [];
    }

    public function validate(): bool
    {
        $this->errors = [];

        $this->validateIfaceIndex();
        $this->validateIfaceRowAmount();
        $this->validateIfaceLang();
        $this->validateSyslogUse();
        if ($this->getSetting('logging', 'syslog_enabled')) {
            $this->validateSyslogIdent();
            $this->validateSyslogFacility();
        }

        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
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

    private function validateSyslogUse(): void
    {
        $syslogEnabled = $this->getSetting('logging', 'syslog_enabled');
        if (!is_bool($syslogEnabled)) {
            $this->errors['syslog_use'] = 'syslog_enabled must be a boolean value (unquoted true or false)';
        }
    }

    private function validateSyslogIdent(): void
    {
        $syslogIdent = $this->getSetting('logging', 'syslog_identity');
        if (!is_string($syslogIdent) || empty($syslogIdent)) {
            $this->errors['syslog_ident'] = 'syslog_identity must be a non-empty string';
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
            $this->errors['syslog_facility'] = "syslog_facility must be an unquoted value and one of the following values: $validFacilitiesList";
        }
    }

    private function validateIfaceRowAmount(): void
    {
        $rowsPerPage = $this->getSetting('interface', 'rows_per_page');
        if (!is_int($rowsPerPage) || $rowsPerPage <= 0) {
            $this->errors['iface_rowamount'] = 'rows_per_page must be a positive integer';
        }
    }

    private function validateIfaceIndex(): void
    {
        // index_display setting removed, no longer needed
        return;
    }

    private function validateIfaceLang(): void
    {
        $language = $this->getSetting('interface', 'language');
        if (!is_string($language) || empty($language)) {
            $this->errors['iface_lang'] = 'language must be a non-empty string';
        }

        $enabledLanguages = $this->getSetting('interface', 'enabled_languages');
        if (empty($enabledLanguages)) {
            $this->errors['iface_enabled_languages'] = 'enabled_languages must be a non-empty string and contain a list of languages separated by commas';
            return;
        }
        
        $enabledLanguagesArray = explode(',', $enabledLanguages);
        if (!in_array($language, $enabledLanguagesArray)) {
            $this->errors['iface_lang'] = 'language must be one of the enabled languages';
        }

        foreach ($enabledLanguagesArray as $lang) {
            if (!is_string($lang) || empty($lang)) {
                $this->errors['iface_enabled_languages'] = 'enabled_languages must be a non-empty string and contain a list of languages separated by commas';
                break;
            }
        }
    }
}
