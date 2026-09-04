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

namespace Poweradmin\Domain\Model;

use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Built-in PowerDNS domain metadata kind definitions.
 *
 * Shared between the web metadata editor and the API v2 metadata endpoint.
 */
class MetadataDefinitions
{
    /**
     * @var array<string, array<string, bool|string|array<int, string>>>
     */
    public const DEFINITIONS = [
        'ALLOW-AXFR-FROM' => [
            'label' => 'ALLOW-AXFR-FROM',
            'multi' => true,
            'placeholder' => '192.0.2.10 or AUTO-NS',
            'help' => 'Allow zone transfers from these IPs or networks. Add one value per row.',
            'api_write' => true,
            'min_version' => '4.0.0',
        ],
        'ALLOW-DNSUPDATE-FROM' => [
            'label' => 'ALLOW-DNSUPDATE-FROM',
            'multi' => true,
            'placeholder' => '192.0.2.20/32',
            'help' => 'Allow dynamic updates from these IPs or networks. Add one value per row.',
            'api_write' => true,
            'min_version' => '4.0.0',
        ],
        'FORWARD-DNSUPDATE' => [
            'label' => 'FORWARD-DNSUPDATE',
            'multi' => false,
            'placeholder' => '1',
            'help' => 'Forward dynamic DNS updates for this zone.',
            'api_write' => true,
            'min_version' => '4.0.0',
        ],
        'ALSO-NOTIFY' => [
            'label' => 'ALSO-NOTIFY',
            'multi' => true,
            'placeholder' => '198.51.100.10:5300',
            'help' => 'Send NOTIFY messages to these extra targets. Add one destination per row.',
            'api_write' => true,
            'min_version' => '4.0.0',
        ],
        'AXFR-SOURCE' => [
            'label' => 'AXFR-SOURCE',
            'multi' => false,
            'placeholder' => '192.0.2.30',
            'help' => 'Use this source address for outgoing AXFR.',
            'api_write' => true,
            'min_version' => '4.0.0',
        ],
        'GSS-ACCEPTOR-PRINCIPAL' => [
            'label' => 'GSS-ACCEPTOR-PRINCIPAL',
            'multi' => false,
            'placeholder' => 'DNS/ns1.example.com@REALM',
            'help' => 'Kerberos principal used to accept GSS-TSIG requests.',
            'api_write' => true,
            // GSS-TSIG existed in 4.0, was removed in 4.3.1 and restored in 4.7.0.
            // Gated at 4.7.0 like its sibling rather than modelling the gap.
            'min_version' => '4.7.0',
        ],
        'GSS-ALLOW-AXFR-PRINCIPAL' => [
            'label' => 'GSS-ALLOW-AXFR-PRINCIPAL',
            'multi' => true,
            'placeholder' => 'host/ns1.example.com@REALM',
            'help' => 'Allow these GSS principals to retrieve AXFR for the zone. Add one principal per row.',
            'api_write' => true,
            'min_version' => '4.7.0',
        ],
        'IXFR' => [
            'label' => 'IXFR',
            'multi' => false,
            'placeholder' => '1',
            'help' => 'Enable or disable IXFR behaviour for the zone.',
            'api_write' => true,
            'min_version' => '4.0.0',
        ],
        'NOTIFY-DNSUPDATE' => [
            'label' => 'NOTIFY-DNSUPDATE',
            'multi' => false,
            'placeholder' => '1',
            'help' => 'Trigger NOTIFY when a DNS update changes the zone.',
            'api_write' => true,
            'min_version' => '4.0.0',
        ],
        'SOA-EDIT-DNSUPDATE' => [
            'label' => 'SOA-EDIT-DNSUPDATE',
            'multi' => false,
            'placeholder' => 'DEFAULT',
            'help' => 'SOA serial policy applied after DNS updates.',
            'api_write' => true,
            'min_version' => '4.0.0',
            'options' => ['DEFAULT', 'INCREASE', 'EPOCH', 'SOA-EDIT', 'SOA-EDIT-INCREASE'],
        ],
        'TSIG-ALLOW-AXFR' => [
            'label' => 'TSIG-ALLOW-AXFR',
            'multi' => true,
            'placeholder' => 'axfr-key-name',
            'help' => 'TSIG keys allowed for AXFR. Add one key name per row.',
            'api_write' => true,
            'min_version' => '4.0.0',
        ],
        'TSIG-ALLOW-DNSUPDATE' => [
            'label' => 'TSIG-ALLOW-DNSUPDATE',
            'multi' => true,
            'placeholder' => 'update-key-name',
            'help' => 'TSIG keys allowed for DNS updates. Add one key name per row.',
            'api_write' => true,
            'min_version' => '4.0.0',
        ],
        'AXFR-MASTER-TSIG' => [
            'label' => 'AXFR-MASTER-TSIG',
            'multi' => false,
            'placeholder' => 'primary-key-name',
            'help' => 'TSIG key used to retrieve the zone from the primary.',
            'api_write' => false,
        ],
        'LUA-AXFR-SCRIPT' => [
            'label' => 'LUA-AXFR-SCRIPT',
            'multi' => false,
            'placeholder' => '/path/to/script.lua',
            'help' => 'Lua script filtering an incoming AXFR.',
            'api_write' => false,
            'operator_only' => true,
        ],
        'NSEC3NARROW' => [
            'label' => 'NSEC3NARROW',
            'multi' => false,
            'placeholder' => '1',
            'help' => 'Use NSEC3 narrow mode. Only takes effect together with NSEC3PARAM.',
            'api_write' => false,
            'options' => ['0', '1'],
        ],
        'NSEC3PARAM' => [
            'label' => 'NSEC3PARAM',
            'multi' => false,
            'placeholder' => '1 0 0 -',
            'help' => 'NSEC3 parameters as "algorithm flags iterations salt". Removing the row switches the zone back to NSEC.',
            'api_write' => false,
        ],
        'PRESIGNED' => [
            'label' => 'PRESIGNED',
            'multi' => false,
            'placeholder' => '1',
            'help' => 'The zone carries its own RRSIGs.',
            'api_write' => false,
        ],
        'PUBLISH-CDNSKEY' => [
            'label' => 'PUBLISH-CDNSKEY',
            'multi' => false,
            'placeholder' => '1',
            'help' => 'Publish CDNSKEY records for the zone KSKs.',
            'api_write' => true,
            'min_version' => '4.0.0',
        ],
        'PUBLISH-CDS' => [
            'label' => 'PUBLISH-CDS',
            'multi' => true,
            'placeholder' => '2',
            'help' => 'Publish CDS records for these digest algorithm numbers. Use 0 to publish the delete CDS.',
            'api_write' => true,
            'min_version' => '4.0.0',
        ],
        'RFC1123-CONFORMANCE' => [
            'label' => 'RFC1123-CONFORMANCE',
            'multi' => false,
            'placeholder' => '0',
            'help' => 'New in PowerDNS 5.1.0. Set to 0 to allow underscores beyond RFC 1123 hostname rules.',
            'api_write' => true,
            'min_version' => '5.1.0',
        ],
        'SIGNALING-ZONE' => [
            'label' => 'SIGNALING-ZONE',
            'multi' => false,
            'placeholder' => '1',
            'help' => 'New in PowerDNS 5.0.0. Enable RFC 9615 signaling zone behaviour.',
            'api_write' => true,
            'min_version' => '5.0.0',
        ],
        'SLAVE-RENOTIFY' => [
            'label' => 'SLAVE-RENOTIFY',
            'multi' => false,
            'placeholder' => '1',
            'help' => 'New in PowerDNS 4.3.0. Renotify secondaries after a fresh AXFR from the primary.',
            'api_write' => true,
            'min_version' => '4.3.0',
        ],
        'SOA-EDIT' => [
            'label' => 'SOA-EDIT',
            'multi' => false,
            'placeholder' => 'INCEPTION-INCREMENT',
            'help' => 'SOA serial policy applied when the zone is served (DNSSEC).',
            'api_write' => false,
            'options' => ['INCEPTION-INCREMENT', 'INCREMENT-WEEKS', 'EPOCH', 'INCEPTION-EPOCH', 'NONE'],
        ],
        'API-RECTIFY' => [
            'label' => 'API-RECTIFY',
            'multi' => false,
            'placeholder' => '1',
            'help' => 'Rectify the zone after API changes.',
            'api_write' => false,
            'options' => ['0', '1'],
        ],
        'ENABLE-LUA-RECORDS' => [
            'label' => 'ENABLE-LUA-RECORDS',
            'multi' => false,
            'placeholder' => '1',
            'help' => 'Allow LUA records in this zone.',
            'api_write' => false,
            'operator_only' => true,
        ],
        'SOA-EDIT-API' => [
            'label' => 'SOA-EDIT-API',
            'multi' => false,
            'placeholder' => 'DEFAULT',
            'help' => 'SOA serial update policy for API changes. Remove the row to fall back to the server default.',
            'api_write' => true,
            'min_version' => '4.0.0',
            'options' => ['DEFAULT', 'INCREASE', 'EPOCH', 'SOA-EDIT', 'SOA-EDIT-INCREASE'],
        ],
    ];

    /**
     * Get the definition for a specific metadata kind.
     *
     * @return array<string, bool|string|array<int, string>>|null
     */
    public static function get(string $kind): ?array
    {
        return self::DEFINITIONS[$kind] ?? null;
    }

    /**
     * Check if a metadata kind is writable via the API.
     */
    public static function isApiWritable(string $kind): bool
    {
        $definition = self::get($kind);
        if ($definition === null) {
            return true; // Custom kinds are writable
        }
        return (bool)($definition['api_write'] ?? true);
    }

    /**
     * Check whether a metadata kind may only be set by a superuser.
     *
     * These kinds make PowerDNS evaluate operator-supplied Lua, so a zone-level
     * editor setting them would reach past the zone they administer.
     */
    public static function isOperatorOnly(string $kind): bool
    {
        $definition = self::get($kind);
        if ($definition === null) {
            return false;
        }
        return (bool)($definition['operator_only'] ?? false);
    }

    /**
     * Config keys (dns group) that narrow the offered values per kind.
     */
    public const OPTION_CONFIG_KEYS = [
        'SOA-EDIT-API' => 'soa_edit_api_options',
        'SOA-EDIT-DNSUPDATE' => 'soa_edit_api_options',
        'SOA-EDIT' => 'soa_edit_options',
    ];

    /**
     * The kinds the backend providers may write as a serial policy; a narrower
     * allowlist than ZONE_PROPERTY_KINDS on purpose.
     */
    public const SERIAL_POLICY_PROPERTY_KINDS = [
        'SOA-EDIT-API' => 'soa_edit_api',
        'SOA-EDIT' => 'soa_edit',
    ];

    /**
     * Kinds PowerDNS rejects on the /metadata endpoint and accepts as a field
     * on the zone object instead (kind => property name).
     */
    public const ZONE_PROPERTY_KINDS = [
        'SOA-EDIT-API' => 'soa_edit_api',
        'SOA-EDIT' => 'soa_edit',
        'API-RECTIFY' => 'api_rectify',
        'NSEC3PARAM' => 'nsec3param',
        'NSEC3NARROW' => 'nsec3narrow',
    ];

    /**
     * Zone properties PowerDNS types as JSON booleans rather than strings.
     */
    public const BOOLEAN_ZONE_PROPERTY_KINDS = [
        'API-RECTIFY',
        'NSEC3NARROW',
    ];

    /**
     * Kinds PowerDNS maintains itself. They are shown read-only and never
     * written back; PUTting them to /metadata is rejected with 422.
     */
    public const SERVER_MANAGED_KINDS = [
        'CATALOG-HASH',
    ];

    /**
     * PowerDNS only accepts unknown metadata kinds when they carry this prefix.
     */
    public const CUSTOM_KIND_API_PREFIX = 'X-';

    public const REJECT_SERVER_MANAGED = 'server_managed';
    public const REJECT_NO_API_ROUTE = 'no_api_route';
    public const REJECT_CUSTOM_PREFIX = 'custom_needs_prefix';

    /**
     * Check whether a submitted value needs a companion kind to have any
     * effect. PowerDNS ignores NSEC3NARROW unless the zone also has NSEC3PARAM.
     */
    public static function requiredCompanionKind(string $kind, string $value): ?string
    {
        if ($kind !== 'NSEC3NARROW' || $value === '' || $value === '0') {
            return null;
        }

        return 'NSEC3PARAM';
    }

    /**
     * Check whether PowerDNS maintains this kind itself.
     */
    public static function isServerManaged(string $kind): bool
    {
        return in_array($kind, self::SERVER_MANAGED_KINDS, true);
    }

    /**
     * Classify why the active backend cannot store a kind, or null when it can.
     *
     * Callers turn the reason into their own message and status; keeping the
     * rule (and its ordering) here is what keeps the web editor and API v2
     * answering the same question the same way.
     */
    public static function writeRejection(string $kind, bool $apiBackend): ?string
    {
        if (self::isServerManaged($kind)) {
            return self::REJECT_SERVER_MANAGED;
        }

        // The domainmetadata table takes any kind and any value.
        if (!$apiBackend) {
            return null;
        }

        if (!isset(self::ZONE_PROPERTY_KINDS[$kind]) && !self::isApiWritable($kind)) {
            return self::REJECT_NO_API_ROUTE;
        }

        if (!isset(self::DEFINITIONS[$kind]) && !str_starts_with($kind, self::CUSTOM_KIND_API_PREFIX)) {
            return self::REJECT_CUSTOM_PREFIX;
        }

        return null;
    }

    /**
     * Convert a stored metadata value to the type the zone object expects.
     */
    public static function toZonePropertyValue(string $kind, string $value): bool|string
    {
        return in_array($kind, self::BOOLEAN_ZONE_PROPERTY_KINDS, true) ? $value === '1' : $value;
    }

    /**
     * Convert a zone object value back to its metadata row representation.
     */
    public static function fromZonePropertyValue(string $kind, mixed $value): string
    {
        return in_array($kind, self::BOOLEAN_ZONE_PROPERTY_KINDS, true)
            ? ($value ? '1' : '')
            : (string) $value;
    }

    /**
     * Build metadata rows from a PowerDNS /metadata listing plus the zone
     * object. Zone-property kinds appear in both, and the zone object wins.
     *
     * @param array<int, array<string, mixed>> $apiMetadata
     * @param array<string, mixed>|null $zoneData
     * @return array<int, array{kind: string, content: string}>
     */
    public static function rowsFromApiPayload(array $apiMetadata, ?array $zoneData): array
    {
        $rows = [];

        foreach ($apiMetadata as $entry) {
            $kind = (string) ($entry['kind'] ?? '');
            if (isset(self::ZONE_PROPERTY_KINDS[$kind])) {
                continue;
            }
            foreach (($entry['metadata'] ?? []) as $value) {
                $rows[] = ['kind' => $kind, 'content' => (string) $value];
            }
        }

        // The zone object reports a boolean kind as false both when it holds "0"
        // and when it is absent, so a false must not become a row of its own.
        foreach (self::ZONE_PROPERTY_KINDS as $kind => $property) {
            if (!empty($zoneData[$property])) {
                $rows[] = ['kind' => $kind, 'content' => self::fromZonePropertyValue($kind, $zoneData[$property])];
            }
        }

        return $rows;
    }

    /**
     * Zone-creation choice that explicitly disables SOA-EDIT-API (stored as
     * an empty value, distinct from leaving the server default in place).
     */
    public const SOA_EDIT_API_OFF = 'OFF';

    /**
     * Get the allowed values for a metadata kind, or null when any value is accepted.
     *
     * @return array<int, string>|null
     */
    public static function getOptions(string $kind): ?array
    {
        $definition = self::get($kind);
        if ($definition === null || !isset($definition['options'])) {
            return null;
        }
        return $definition['options'];
    }

    /**
     * Get the values offered by the UI for a kind, narrowed by the matching
     * dns.* config list when set. An empty result means the kind's options
     * are all disabled by configuration; null means free-form input.
     *
     * @return array<int, string>|null
     */
    public static function getOfferedOptions(string $kind, ConfigurationInterface $config): ?array
    {
        $options = self::getOptions($kind);
        $configKey = self::OPTION_CONFIG_KEYS[$kind] ?? null;
        if ($options === null || $configKey === null) {
            return $options;
        }

        $configured = $config->get('dns', $configKey);
        if (!is_array($configured)) {
            return $options;
        }

        return array_values(array_intersect($options, $configured));
    }

    /**
     * The values an editor may store for a kind, or null when any value goes.
     *
     * A kind whose options config narrows to nothing is hidden from the UI but
     * still accepts every PowerDNS value, so rows stored before the restriction
     * keep saving.
     *
     * @return array<int, string>|null
     */
    public static function getAllowedValues(string $kind, ConfigurationInterface $config): ?array
    {
        $options = self::getOfferedOptions($kind, $config);

        return $options === [] ? self::getOptions($kind) : $options;
    }

    /**
     * SOA-EDIT-API choices for zone creation: the metadata values plus 'OFF',
     * narrowed by the dns.soa_edit_api_options config list when set.
     *
     * @return array<int, string>
     */
    public static function getSoaEditApiChoices(ConfigurationInterface $config): array
    {
        $choices = array_merge(self::getOptions('SOA-EDIT-API'), [self::SOA_EDIT_API_OFF]);

        $configured = $config->get('dns', self::OPTION_CONFIG_KEYS['SOA-EDIT-API']);
        if (!is_array($configured)) {
            return $choices;
        }

        return array_values(array_intersect($choices, $configured));
    }

    /**
     * Check if a metadata kind accepts multiple values.
     */
    public static function isMultiValue(string $kind): bool
    {
        $definition = self::get($kind);
        if ($definition === null) {
            return true; // Custom kinds default to multi
        }
        return (bool)($definition['multi'] ?? false);
    }
}
