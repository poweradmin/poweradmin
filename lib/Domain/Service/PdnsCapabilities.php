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

namespace Poweradmin\Domain\Service;

/**
 * Maps a PowerDNS Authoritative Server version string to feature capability flags
 * used by the UI to show, hide, or default behaviour appropriately.
 *
 * The version timeline reflected here is taken from the upstream changelog
 * (docs/changelog/4.4.rst .. 5.1.rst). When the connected version is unknown
 * (empty string, or detection failed) every capability method returns false
 * (strict mode): the UI must hide newer features whose support cannot be
 * confirmed. This is the fail-safe choice - showing options the server then
 * rejects is worse than briefly hiding them while the API is unreachable.
 */
final class PdnsCapabilities
{
    private string $version;

    private function __construct(string $version)
    {
        $this->version = $version;
    }

    /**
     * Build a capability set from a raw version string. Strips any non-numeric
     * prefix (PowerDNS sometimes prefixes the build with text like "git-").
     * A null or empty version yields an "unknown" capability set.
     */
    public static function fromVersion(?string $version): self
    {
        $version = (string) $version;
        $version = preg_replace('/^[^0-9]*/', '', $version) ?? '';
        return new self($version);
    }

    public function version(): string
    {
        return $this->version;
    }

    public function isKnown(): bool
    {
        return $this->version !== '';
    }

    /**
     * True when the connected server is at least $minVersion. When the version
     * is unknown, returns $whenUnknown (default false - conservative).
     */
    public function isAtLeast(string $minVersion, bool $whenUnknown = false): bool
    {
        if (!$this->isKnown()) {
            return $whenUnknown;
        }
        return version_compare($this->version, $minVersion, '>=');
    }

    /* ----- Terminology ------------------------------------------------- */

    /** Primary/Secondary aliases were added in 4.5; older servers only know Master/Slave. */
    public function prefersPrimarySecondaryTerminology(): bool
    {
        return $this->isAtLeast('4.5.0');
    }

    /* ----- Zone kinds -------------------------------------------------- */

    /** Catalog zones (Producer/Consumer kinds) introduced in 4.7. */
    public function supportsCatalogZones(): bool
    {
        return $this->isAtLeast('4.7.0');
    }

    /** Per-zone/network Views introduced in 5.0. */
    public function supportsViews(): bool
    {
        return $this->isAtLeast('5.0.0');
    }

    /* ----- Record types ------------------------------------------------ */

    /**
     * Whether the connected server understands the given DNS record type.
     * Only types whose support actually changed across recent versions are
     * gated here; common types (A, AAAA, MX, etc.) are assumed always
     * supported and return true.
     */
    public function supportsRecordType(string $type): bool
    {
        $type = strtoupper(trim($type));

        $minVersion = match ($type) {
            'SVCB', 'HTTPS', 'APL' => '4.4.0',
            'CSYNC', 'NID', 'L32', 'L64', 'LP' => '4.5.0',
            'ZONEMD' => '4.8.0',
            'WALLET' => '5.1.0',
            default => null,
        };

        if ($minVersion === null) {
            return true;
        }
        return $this->isAtLeast($minVersion);
    }

    /* ----- API endpoints ---------------------------------------------- */

    /** Individual RRset fetch endpoint added in 4.6. */
    public function supportsIndividualRrsetFetch(): bool
    {
        return $this->isAtLeast('4.6.0');
    }

    /** Autoprimary management via API added in 4.6. */
    public function supportsAutoprimariesApi(): bool
    {
        return $this->isAtLeast('4.6.0');
    }

    /** Per-record last-modified timestamps in API responses added in 4.9. */
    public function supportsRecordTimestamps(): bool
    {
        return $this->isAtLeast('4.9.0');
    }

    /* ----- DNSSEC ----------------------------------------------------- */

    /**
     * PowerDNS 4.0+ uses CSK (Combined Signing Key) by default. Older servers
     * default to KSK+ZSK and the UI should reflect that.
     */
    public function supportsDefaultCsk(): bool
    {
        return $this->isAtLeast('4.0.0');
    }

    /** PEM import/export of DNSSEC keys added in 4.7. */
    public function supportsPemKeyImportExport(): bool
    {
        return $this->isAtLeast('4.7.0');
    }

    /** RFC 9615 authenticated DNSSEC bootstrapping added in 5.0. */
    public function supportsRfc9615Bootstrap(): bool
    {
        return $this->isAtLeast('5.0.0');
    }

    /* ----- Zone metadata ---------------------------------------------- */

    /**
     * Whether a metadata kind whose minimum version is $minVersion should be
     * shown for the connected server. A null/empty minimum means the kind has
     * always been supported. When the connected version is unknown the kind is
     * hidden (strict mode) so admins don't get options the server may reject.
     */
    public function supportsMetadataKind(?string $minVersion): bool
    {
        if ($minVersion === null || $minVersion === '') {
            return true;
        }
        return $this->isAtLeast($minVersion);
    }
}
