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

namespace Poweradmin\Domain\Service;

use Poweradmin\AppConfiguration;

class DnsFormatter {
    private AppConfiguration $config;

    public function __construct(AppConfiguration $config)
    {
        $this->config = $config;
    }

    public function formatContent(string $type, string $content): string
    {
        return match ($type) {
            'TXT' => $this->formatTxtContent($content),
            default => $content,
        };
    }

    private function formatTxtContent(string $content): string
    {
        if (!$this->config->get('dns_txt_auto_quote')) {
            return $content;
        }

        $content = trim($content);
        if ($content === '' || (str_starts_with($content, '"') && str_ends_with($content, '"'))) {
            return $content;
        }

        return sprintf('"%s"', $content);
    }
}