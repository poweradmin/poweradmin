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

namespace Poweradmin\Module\ZoneImportExport\Service;

/**
 * Generates standard BIND zone files from DNS records.
 * Output is compatible with Cloudflare import and pdnsutil load-zone.
 */
class BindZoneFileGenerator
{
    /**
     * Generate a BIND zone file from database records.
     *
     * @param string $zoneName The zone name
     * @param array $records Array of record arrays with keys: name, type, content, ttl, prio
     * @return string The zone file content
     */
    public function generate(string $zoneName, array $records): string
    {
        $output = [];

        // Header comment
        $output[] = sprintf(';; Zone: %s.', $zoneName);
        $output[] = sprintf(';; Exported: %s', date('Y-m-d H:i:s'));
        $output[] = ';;';

        // Find SOA record for default TTL
        $soaRecord = null;
        $defaultTtl = 86400;
        foreach ($records as $record) {
            if ($record['type'] === 'SOA') {
                $soaRecord = $record;
                $defaultTtl = (int)$record['ttl'];
                break;
            }
        }

        $output[] = sprintf('$ORIGIN %s.', $zoneName);
        $output[] = sprintf('$TTL %d', $defaultTtl);
        $output[] = '';

        // Group records by type for organized output
        $typeOrder = ['SOA', 'NS', 'MX', 'A', 'AAAA', 'CNAME', 'TXT', 'SRV', 'CAA'];
        $grouped = [];
        $otherRecords = [];

        foreach ($records as $record) {
            $type = $record['type'];
            if (in_array($type, $typeOrder, true)) {
                $grouped[$type][] = $record;
            } else {
                $otherRecords[] = $record;
            }
        }

        // Output records in order
        foreach ($typeOrder as $type) {
            if (!isset($grouped[$type])) {
                continue;
            }

            $output[] = sprintf('; %s Records', $type);
            foreach ($grouped[$type] as $record) {
                $output[] = $this->formatRecord($record, $zoneName);
            }
            $output[] = '';
        }

        // Output remaining records
        if (!empty($otherRecords)) {
            $output[] = '; Other Records';
            foreach ($otherRecords as $record) {
                $output[] = $this->formatRecord($record, $zoneName);
            }
            $output[] = '';
        }

        return implode("\n", $output) . "\n";
    }

    private function formatRecord(array $record, string $zoneName): string
    {
        $name = $record['name'];
        $ttl = (int)$record['ttl'];
        $type = $record['type'];
        $content = $record['content'];
        $prio = isset($record['prio']) ? (int)$record['prio'] : 0;

        // Make name a FQDN with trailing dot
        if (!str_ends_with($name, '.')) {
            $name .= '.';
        }

        // Format content based on type
        $formattedContent = $this->formatContent($type, $content, $prio, $zoneName);

        return sprintf('%s %d IN %s %s', $name, $ttl, $type, $formattedContent);
    }

    private function formatContent(string $type, string $content, int $prio, string $zoneName): string
    {
        switch ($type) {
            case 'MX':
            case 'KX':
                $target = $this->ensureTrailingDot($content);
                return sprintf('%d %s', $prio, $target);

            case 'SRV':
                // PowerDNS stores SRV as "weight port target" with priority separate
                $parts = preg_split('/\s+/', $content);
                if (count($parts) >= 3) {
                    $target = $this->ensureTrailingDot($parts[2]);
                    return sprintf('%d %s %s %s', $prio, $parts[0], $parts[1], $target);
                }
                return sprintf('%d %s', $prio, $content);

            case 'NS':
            case 'CNAME':
            case 'PTR':
            case 'DNAME':
                return $this->ensureTrailingDot($content);

            case 'SOA':
                $parts = preg_split('/\s+/', $content);
                if (count($parts) >= 7) {
                    $parts[0] = $this->ensureTrailingDot($parts[0]);
                    $parts[1] = $this->ensureTrailingDot($parts[1]);
                    return implode(' ', $parts);
                }
                return $content;

            case 'NAPTR':
                $parts = preg_split('/\s+/', $content);
                if (count($parts) >= 6) {
                    $parts[5] = $this->ensureTrailingDot($parts[5]);
                    return implode(' ', $parts);
                }
                return $content;

            default:
                return $content;
        }
    }

    private function ensureTrailingDot(string $name): string
    {
        if ($name === '' || $name === '.') {
            return '.';
        }
        return str_ends_with($name, '.') ? $name : $name . '.';
    }
}
