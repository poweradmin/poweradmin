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
        if ($this->config['syslog_use']) {
            $this->validateSyslogIdent();
            $this->validateSyslogFacility();
        }

        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    private function validateSyslogUse(): void
    {
        if (!is_bool($this->config['syslog_use'])) {
            $this->errors['syslog_use'] = 'syslog_use must be a boolean value (unquoted true or false)';
        }
    }

    private function validateSyslogIdent(): void
    {
        if (!is_string($this->config['syslog_ident']) || empty($this->config['syslog_ident'])) {
            $this->errors['syslog_ident'] = 'syslog_ident must be a non-empty string';
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

        if (!in_array($this->config['syslog_facility'], $validFacilities)) {
            $validFacilitiesList = implode(', ', array_keys($validFacilities));
            $this->errors['syslog_facility'] = "syslog_facility must be an unquoted value and one of the following values: $validFacilitiesList";
        }
    }

    private function validateIfaceRowAmount(): void
    {
        if (!is_int($this->config['iface_rowamount']) && $this->config['iface_rowamount'] <= 0) {
            $this->errors['iface_rowamount'] = 'iface_rowamount must be a positive integer';
        }
    }

    private function validateIfaceIndex(): void
    {
        $validIndexes = ['cards', 'list'];
        $ifaceIndex = $this->config['iface_index'] ?? null;
        if (!in_array($ifaceIndex, $validIndexes)) {
            $validIndexesList = implode(', ', $validIndexes);
            $this->errors['iface_index'] = "iface_index must be an string and one of the following values: $validIndexesList";
        }
    }

    private function validateIfaceLang(): void
    {
        if (!is_string($this->config['iface_lang']) || empty($this->config['iface_lang'])) {
            $this->errors['iface_lang'] = 'iface_lang must be a non-empty string';
        }

        $enabledLanguages = explode(',', $this->config['iface_enabled_languages']);
        if (!in_array($this->config['iface_lang'], $enabledLanguages)) {
            $this->errors['iface_lang'] = 'iface_lang must be one of the enabled languages';
        }

        foreach ($enabledLanguages as $language) {
            if (!is_string($language) || empty($language)) {
                $this->errors['iface_enabled_languages'] = 'iface_enabled_languages must be a non-empty string and contain a list of languages separated by commas';
                break;
            }
        }
    }
}
