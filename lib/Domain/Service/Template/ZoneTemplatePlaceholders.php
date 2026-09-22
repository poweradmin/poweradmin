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

namespace Poweradmin\Domain\Service\Template;

use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\Dns\DomainParsingService;

/**
 * Expands the [ZONE], [NS1], [SERIAL] style placeholders of zone template records.
 */
class ZoneTemplatePlaceholders
{
    private ConfigurationInterface $config;
    private DomainParsingService $domainParser;

    public function __construct(ConfigurationInterface $config, DomainParsingService $domainParser)
    {
        $this->config = $config;
        $this->domainParser = $domainParser;
    }

    /**
     * Replace the zone name and site-specific values in a zone's records with
     * template placeholders, for saving a zone as a template.
     *
     * @param array $record Record with name, content and optionally type
     * @param array $options NS1 and HOSTMASTER values to recognise in an SOA
     * @return array [name, content] with placeholders
     */
    public function replaceWithTemplatePlaceholders(string $domain, array $record, array $options = []): array
    {
        if (empty($domain)) {
            return [$record['name'], $record['content']];
        }

        $domainComponents = $this->domainParser->parseDomain($domain);
        $domainName = $domainComponents['domain'];
        $tld = $domainComponents['tld'];

        $pattern = '/(\\.)?' . preg_quote($domain, '/') . '$/';
        $name = preg_replace($pattern, '$1[ZONE]', $record['name']);
        $content = preg_replace($pattern, '$1[ZONE]', $record['content']);

        // Content such as example-com.mail.protection.outlook.com cannot use
        // [ZONE] directly, so only the hyphenated domain-tld form is replaced.
        if (!empty($domainName) && !empty($tld)) {
            $content = str_replace($domainName . '-' . $tld, '[DOMAIN]-[TLD]', $content);
        }

        if (isset($record['type']) && $record['type'] === 'SOA') {
            $parts = explode(' ', $content);

            if (isset($options['NS1']) && $parts[0] === $options['NS1']) {
                $parts[0] = '[NS1]';
            }

            if (isset($options['HOSTMASTER']) && $parts[1] === $options['HOSTMASTER']) {
                $parts[1] = '[HOSTMASTER]';
            }

            // Any numeric serial becomes [SERIAL]; a literal serial in a template
            // would only stamp stale values into zones created from it. Serial 0
            // is kept: it means autoserial and getNextSerial() preserves it.
            if (isset($parts[2]) && ctype_digit($parts[2]) && $parts[2] !== '0') {
                $parts[2] = '[SERIAL]';
            }

            $content = implode(' ', $parts);
        }

        return [$name, $content];
    }

    /**
     * Parse string and substitute domain and serial
     *
     * @param string $val string to parse containing tokens like '[ZONE]', '[SERIAL]', '[UNIXTIME]' or '[COUNTER]'
     * @param string $domain domain to substitute for '[ZONE]'
     * @param string|null $recordType record type of $val; when given it authoritatively
     *        decides SOA-timer completion. Legacy 2-arg callers fall back to a heuristic.
     *
     * @return string interpolated/parsed string
     */
    public function parseTemplateValue(string $val, string $domain, ?string $recordType = null): string
    {
        $dns_ns1 = $this->config->get('dns', 'ns1');
        $dns_ns2 = $this->config->get('dns', 'ns2');
        $dns_ns3 = $this->config->get('dns', 'ns3');
        $dns_ns4 = $this->config->get('dns', 'ns4');
        $dns_hostmaster = $this->config->get('dns', 'hostmaster');

        $soa_refresh = $this->config->get('dns', 'soa_refresh');
        $soa_retry = $this->config->get('dns', 'soa_retry');
        $soa_expire = $this->config->get('dns', 'soa_expire');
        $soa_minimum = $this->config->get('dns', 'soa_minimum');

        $serial = date("Ymd") . "00";

        $domainComponents = $this->domainParser->parseDomain($domain);
        $domainName = $domainComponents['domain'];
        $tld = $domainComponents['tld'];

        $val = str_replace('[ZONE]', $domain, $val);
        $val = str_replace('[DOMAIN]', $domainName, $val);
        $val = str_replace('[TLD]', $tld, $val);
        $val = str_replace('[SERIAL]', $serial, $val);
        // Alternative SOA serial formats: both stay below 1979999999, so
        // getNextSerial() treats them as plain counters and bumps them by 1.
        $val = str_replace('[UNIXTIME]', (string)time(), $val);
        $val = str_replace('[COUNTER]', '1', $val);
        $val = str_replace('[NS1]', $dns_ns1, $val);
        $val = str_replace('[NS2]', $dns_ns2, $val);
        $val = str_replace('[NS3]', $dns_ns3, $val);
        $val = str_replace('[NS4]', $dns_ns4, $val);
        $val = str_replace('[HOSTMASTER]', $dns_hostmaster, $val);

        $val = str_replace('[SOA_REFRESH]', $soa_refresh, $val);
        $val = str_replace('[SOA_RETRY]', $soa_retry, $val);
        $val = str_replace('[SOA_EXPIRE]', $soa_expire, $val);
        $val = str_replace('[SOA_MINIMUM]', $soa_minimum, $val);

        // Only SOA content gets timer completion. Without an explicit record type
        // (legacy 2-arg callers) the old substring heuristic decides.
        $isSoaValue = $recordType !== null
            ? $recordType === RecordType::SOA
            : str_contains($val, 'SOA');
        if ($isSoaValue) {
            // A complete SOA rdata has at least 7 fields:
            // primary hostmaster serial refresh retry expire minimum
            if (count(explode(' ', $val)) < 7) {
                $val .= " $soa_refresh $soa_retry $soa_expire $soa_minimum";
            }
        }

        if ($recordType === RecordType::TXT) {
            $val = self::quoteTxt($val);
        }

        return $val;
    }

    /**
     * Template TXT content is stored as typed, but PowerDNS only accepts the
     * presentation form: an unquoted "v=spf1 mx -all" is three strings, and the
     * API refuses it outright.
     */
    private static function quoteTxt(string $content): string
    {
        $content = trim($content);
        if ($content === '' || (str_starts_with($content, '"') && str_ends_with($content, '"'))) {
            return $content;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $content) . '"';
    }
}
