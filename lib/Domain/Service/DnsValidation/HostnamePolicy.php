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

namespace Poweradmin\Domain\Service\DnsValidation;

use Poweradmin\Domain\Config\ConfigurationInterface;

/**
 * The three dns.* settings that decide how strictly a hostname's labels and TLD are checked.
 */
final readonly class HostnamePolicy
{
    /**
     * @param bool $topLevelTldCheck Reject single-label names
     * @param bool $strictTldCheck Require a known TLD (IANA list or custom list)
     * @param string[] $customTlds Extra TLDs accepted under strict checking
     */
    public function __construct(
        public bool $topLevelTldCheck = false,
        public bool $strictTldCheck = false,
        public array $customTlds = [],
    ) {
    }

    public static function fromConfig(ConfigurationInterface $config): self
    {
        $customTlds = $config->get('dns', 'custom_tlds', []);

        return new self(
            (bool)$config->get('dns', 'top_level_tld_check'),
            (bool)$config->get('dns', 'strict_tld_check'),
            is_array($customTlds) ? $customTlds : [],
        );
    }

    /**
     * Whether the TLD is on the custom list (case-insensitive).
     */
    public function allowsCustomTld(string $tld): bool
    {
        return in_array(strtolower($tld), array_map('strtolower', $this->customTlds), true);
    }
}
