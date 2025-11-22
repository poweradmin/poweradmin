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

use Poweradmin\Domain\Model\RecordDisplay;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Utility\IpHelper;

/**
 * Service responsible for transforming DNS records for display purposes
 * Implements the Single Responsibility Principle by focusing only on display transformations
 */
class RecordDisplayService
{
    private bool $displayHostnameOnly;

    public function __construct(bool $displayHostnameOnly = false)
    {
        $this->displayHostnameOnly = $displayHostnameOnly;
    }

    /**
     * Transform a single record for display
     *
     * @param array $record The record data
     * @param string $zoneName The zone name
     * @return RecordDisplay The transformed record for display
     */
    public function transformRecord(array $record, string $zoneName): RecordDisplay
    {
        $displayName = $record['name'];
        $editableName = $record['name'];

        if ($this->displayHostnameOnly) {
            $displayName = DnsHelper::stripZoneSuffix($record['name'], $zoneName);
            $editableName = $displayName;
        }

        // Shorten IPv6 addresses in AAAA record content for display
        $transformedRecord = $record;
        if (isset($record['type']) && $record['type'] === 'AAAA' && isset($record['content'])) {
            $transformedRecord['content'] = IpHelper::shortenIPv6Address($record['content']);
        }

        // Shorten IPv6 reverse zone names (PTR records) for display
        if (isset($record['name']) && str_ends_with($record['name'], '.ip6.arpa')) {
            $shortened = IpHelper::shortenIPv6ReverseZone($record['name']);
            if ($shortened !== null) {
                $displayName = $shortened;
                // Keep editable name as original for form submissions
            }
        }

        return new RecordDisplay(
            $transformedRecord,
            $displayName,
            $editableName,
            $this->displayHostnameOnly
        );
    }

    /**
     * Transform multiple records for display
     *
     * @param array $records Array of records
     * @param string $zoneName The zone name
     * @return array Array of RecordDisplay objects
     */
    public function transformRecords(array $records, string $zoneName): array
    {
        return array_map(
            fn($record) => $this->transformRecord($record, $zoneName),
            $records
        );
    }

    /**
     * Restore the full FQDN from a hostname
     * Used when processing form submissions
     *
     * @param string $hostname The hostname (may be partial or full)
     * @param string $zoneName The zone name
     * @return string The full FQDN
     */
    public function restoreFqdn(string $hostname, string $zoneName): string
    {
        if (!$this->displayHostnameOnly) {
            return $hostname;
        }

        return DnsHelper::restoreZoneSuffix($hostname, $zoneName);
    }

    /**
     * Check if hostname-only display is enabled
     *
     * @return bool
     */
    public function isHostnameOnlyEnabled(): bool
    {
        return $this->displayHostnameOnly;
    }
}
