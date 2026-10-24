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

namespace Poweradmin\Domain\Service\Dns;

use Poweradmin\Domain\Config\ConfigurationInterface;

/**
 * Normalizes record content before saving; wraps TXT content in quotes when dns.txt_auto_quote is on.
 */
class DnsFormatter
{
    private ConfigurationInterface $config;

    public function __construct(ConfigurationInterface $config)
    {
        $this->config = $config;
    }

    public function formatContent(string $type, string $content): string
    {
        return match ($type) {
            'TXT' => $this->formatTxtContent($content),
            default => self::quoteEch($type, $content),
        };
    }

    /**
     * Writes an SVCB or HTTPS ech value in quotes, the form PowerDNS stores and serves.
     * Its API refuses an unquoted ech value ("Not in expected format"); SQL mode reads both.
     */
    public static function quoteEch(string $type, string $content): string
    {
        $type = strtoupper($type);
        if ($type !== 'SVCB' && $type !== 'HTTPS') {
            return $content;
        }

        return (string)preg_replace('/(^|\s)(ech)=([^"\s]+)(?=\s|$)/i', '$1$2="$3"', $content);
    }

    /**
     * The form an SVCB or HTTPS row had when saved before ech was quoted, or null when
     * the content carries no quoted ech value. Duplicate checks look for both forms.
     */
    public static function unquoteEch(string $type, string $content): ?string
    {
        $unquoted = (string)preg_replace('/(^|\s)(ech)="([^"\s]+)"(?=\s|$)/i', '$1$2=$3', $content);

        return $unquoted !== $content && self::quoteEch($type, $unquoted) === $content ? $unquoted : null;
    }

    private function formatTxtContent(string $content): string
    {
        if (!$this->config->get('dns', 'txt_auto_quote')) {
            return $content;
        }

        $content = trim($content);
        if ($content === '' || (str_starts_with($content, '"') && str_ends_with($content, '"'))) {
            return $content;
        }

        return sprintf('"%s"', $content);
    }
}
