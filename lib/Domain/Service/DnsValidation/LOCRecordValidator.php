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

namespace Poweradmin\Domain\Service\DnsValidation;

use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * LOC record validator
 *
 * The LOC (Location) record is used to express geographic location information
 * for a domain name in the DNS. It specifies the latitude, longitude, and altitude
 * of a point, along with the size and precision of the area.
 *
 * Format: d1 [m1 [s1]] {"N"|"S"} d2 [m2 [s2]] {"E"|"W"} alt["m"] [siz["m"] [hp["m"] [vp["m"]]]]
 *
 * Where:
 * - d1: degrees latitude (0-90)
 * - m1: minutes latitude (0-59) (optional, defaults to 0)
 * - s1: seconds latitude (0-59.999) (optional, defaults to 0)
 * - d2: degrees longitude (0-180)
 * - m2: minutes longitude (0-59) (optional, defaults to 0)
 * - s2: seconds longitude (0-59.999) (optional, defaults to 0)
 * - alt: altitude in meters (-100000.00 to 42849672.95) (optional, defaults to 0)
 * - siz: size/diameter of sphere in meters (0-90000000.00) (optional, defaults to 1m)
 * - hp: horizontal precision in meters (0-90000000.00) (optional, defaults to 10000m)
 * - vp: vertical precision in meters (0-90000000.00) (optional, defaults to 10m)
 *
 * Example: 37 46 30.000 N 122 23 30.000 W 0.00m 1m 10000m 10m
 *
 * NOTE: The LOC record is defined in RFC 1876 as an experimental protocol.
 *
 * @see https://www.rfc-editor.org/rfc/rfc1876 RFC 1876: A Means for Expressing Location Information in the Domain Name System
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class LOCRecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates LOC record content
     *
     * @param string $content The content of the LOC record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for LOC records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $errors = [];

        // Validate hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate LOC content
        $contentResult = $this->validateLOCContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for LOC records)
        if (!empty($prio) && $prio != 0) {
            $errors[] = _('Priority field for LOC records must be 0 or empty.');
            return ValidationResult::errors($errors);
        }

        // Add warnings according to RFC 1876
        $warnings = [
            _('NOTE: The LOC record type is defined in RFC 1876 as an experimental protocol, not a formal IETF standard.'),
            _('LOC records are rarely used but can be helpful for geographical mapping of resources.')
        ];

        // Extract components from the LOC record
        preg_match('/^(\d+)(?:\s+(\d+)(?:\s+(\d+(?:\.\d+)?))?)?/i', $content, $matches);
        $latitude = isset($matches[1]) ? (int)$matches[1] : 0;

        // Add warnings for values at the extremes
        if ($latitude === 90) {
            $warnings[] = _('Exact 90 degrees North latitude (the North Pole) detected. This is an extreme point and might indicate incorrect data entry.');
        } elseif ($latitude === 0) {
            $warnings[] = _('Zero latitude detected (the Equator). If this is intentional, it is fine; otherwise, please verify your coordinates.');
        }

        // Add precision warning if the defaults appear to be used
        if (preg_match('/\d+m\s+10000m\s+10m$/i', $content)) {
            $warnings[] = _('Default precision values detected (1m size, 10000m horizontal precision, 10m vertical precision). Consider using more accurate values if you have them.');
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validates the LOC record content format
     *
     * @param string $content LOC record content
     *
     * @return ValidationResult Validation result with success or error message
     */
    private function validateLOCContent(string $content): ValidationResult
    {
        // First validate latitude explicitly before using regex
        if (preg_match('/^(\d+)/', $content, $matches)) {
            $latitude = (int)$matches[1];
            if ($latitude > 90) {
                return ValidationResult::failure(_('Latitude degrees must be between 0 and 90.'));
            }
        }

        // Check for correct N/S and E/W directions
        if (!preg_match('/[NS]/', $content)) {
            return ValidationResult::failure(_('Latitude direction must be specified as N or S.'));
        }

        if (!preg_match('/[EW]/', $content)) {
            return ValidationResult::failure(_('Longitude direction must be specified as E or W.'));
        }

        // Check for longitude value
        if (preg_match('/[NS]\s+(\d+)/', $content, $matches)) {
            $longitude = (int)$matches[1];
            if ($longitude > 180) {
                return ValidationResult::failure(_('Longitude degrees must be between 0 and 180.'));
            }
        }

        // Check minutes and seconds format
        if (preg_match('/(\d+)\s+(\d+)/', $content, $matches)) {
            $minutes = (int)$matches[2];
            if ($minutes > 59) {
                return ValidationResult::failure(_('Minutes must be between 0 and 59.'));
            }

            // Check if seconds are present
            if (preg_match('/(\d+)\s+(\d+)\s+(\d+(\.\d+)?)/', $content, $secondsMatches)) {
                $seconds = (float)$secondsMatches[3];
                if ($seconds >= 60) {
                    return ValidationResult::failure(_('Seconds must be between 0 and 59.999.'));
                }
            }
        }

        // Check altitude range
        if (preg_match('/[EW]\s+(-?\d+(\.\d+)?)[m]?/', $content, $matches)) {
            $altitude = (float)$matches[1];
            if ($altitude < -100000 || $altitude > 42849672.95) {
                return ValidationResult::failure(_('Altitude must be between -100000 and 42849672.95 meters.'));
            }
        }

        // Main regex for complete LOC record validation
        $regex = "^(90|[1-8]\d|0?\d)( ([1-5]\d|0?\d)( ([1-5]\d|0?\d)(\.\d{1,3})?)?)? [NS] "
            . "(180|1[0-7]\d|[1-9]\d|0?\d)( ([1-5]\d|0?\d)( ([1-5]\d|0?\d)(\.\d{1,3})?)?)? [EW] "
            . "(-(100000(\.00)?|\d{1,5}(\.\d\d)?)|([1-3]?\d{1,7}(\.\d\d)?|"
            . "4([01][0-9]{6}|2([0-7][0-9]{5}|8([0-3][0-9]{4}|4([0-8][0-9]{3}|"
            . "9([0-5][0-9]{2}|6([0-6][0-9]|7[01]))))))(\.\d\d)?|"
            . "42849672(\.([0-8]\d|9[0-5]))?))[m]?( (\d{1,7}|[1-8]\d{7})(\.\d\d)?[m]?){0,3}$^";

        if (!preg_match($regex, $content)) {
            // If we've reached here but regex still doesn't match, it's likely an issue with precision values
            if (preg_match('/[EW]\s+[\d\.-]+[m]?/', $content)) {
                // We have at least lat/long/alt but precision values might be wrong
                $precisionPattern = '/[EW]\s+[\d\.-]+[m]?\s+([\d\.]+[m]?\s+[\d\.]+[m]?\s+[\d\.]+[m]?)/';
                if (preg_match($precisionPattern, $content, $precisionMatches)) {
                    return ValidationResult::failure(_('Invalid size, horizontal precision, or vertical precision value. These must be between 0 and 90000000 meters.'));
                }
            }

            // General format error
            return ValidationResult::failure(_('Invalid LOC record format. Format should be: d1 [m1 [s1]] {"N"|"S"} d2 [m2 [s2]] {"E"|"W"} alt["m"] [siz["m"] [hp["m"] [vp["m"]]].'));
        }

        return ValidationResult::success(true);
    }
}
