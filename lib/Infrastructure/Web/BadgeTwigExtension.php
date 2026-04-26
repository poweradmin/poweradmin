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

namespace Poweradmin\Infrastructure\Web;

use Poweradmin\Application\Service\PdnsVersionService;
use Poweradmin\Domain\Service\PdnsCapabilities;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class BadgeTwigExtension extends AbstractExtension
{
    private const RECORD_TYPE_CLASSES = [
        'A' => 'bg-primary',
        'AAAA' => 'bg-info',
        'CNAME' => 'bg-success',
        'MX' => 'bg-warning',
        'TXT' => 'bg-secondary',
        'PTR' => 'bg-danger',
        'SOA' => 'bg-dark',
        'NS' => 'bg-info',
    ];

    private const ZONE_TYPE_CLASSES = [
        'MASTER' => 'bg-success',
        'SLAVE' => 'bg-info',
        'NATIVE' => 'bg-warning',
        'PRODUCER' => 'bg-success',
        'CONSUMER' => 'bg-info',
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('record_type_class', [$this, 'getRecordTypeClass']),
            new TwigFunction('zone_type_class', [$this, 'getZoneTypeClass']),
            new TwigFunction('zone_type_label', [$this, 'getZoneTypeLabel']),
            new TwigFunction('autoprimaries_label', [$this, 'getAutoprimariesLabel']),
        ];
    }

    public function getRecordTypeClass(?string $type): string
    {
        if ($type === null || $type === '') {
            return 'bg-secondary';
        }
        return self::RECORD_TYPE_CLASSES[strtoupper($type)] ?? 'bg-secondary';
    }

    public function getZoneTypeClass(string $type): string
    {
        return self::ZONE_TYPE_CLASSES[strtoupper($type)] ?? 'bg-secondary';
    }

    /**
     * Translate a raw PowerDNS zone kind to the user-visible label, swapping
     * Master/Slave for Primary/Secondary on PowerDNS 4.5+ where the upstream
     * adopted the modern terminology. The internal kind value passed to the
     * API never changes - this only affects what the UI shows.
     */
    public function getZoneTypeLabel(?string $type): string
    {
        if ($type === null || $type === '') {
            return '';
        }

        $caps = $this->resolveCapabilities();
        $upper = strtoupper(trim($type));

        if ($caps->prefersPrimarySecondaryTerminology()) {
            return match ($upper) {
                'MASTER' => _('Primary'),
                'SLAVE' => _('Secondary'),
                'NATIVE' => _('Native'),
                'PRODUCER' => _('Producer'),
                'CONSUMER' => _('Consumer'),
                default => ucfirst(strtolower($upper)),
            };
        }

        return match ($upper) {
            'MASTER' => _('Master'),
            'SLAVE' => _('Slave'),
            'NATIVE' => _('Native'),
            'PRODUCER' => _('Producer'),
            'CONSUMER' => _('Consumer'),
            default => ucfirst(strtolower($upper)),
        };
    }

    /**
     * PowerDNS 4.6 renamed "supermaster" to "autoprimary" in its config and
     * pdnsutil; the API still uses /supermasters but UIs surface the new term.
     * Returns full strings (not composed at the template) so gettext can
     * translate each variant as a single unit.
     */
    public function getAutoprimariesLabel(string $key = 'plural'): string
    {
        $modern = $this->resolveCapabilities()->supportsAutoprimariesApi();

        return match ($key) {
            'plural' => $modern ? _('Autoprimaries') : _('Supermasters'),
            'singular' => $modern ? _('Autoprimary') : _('Supermaster'),
            'add_action' => $modern ? _('Add autoprimary') : _('Add supermaster'),
            'edit_action' => $modern ? _('Edit autoprimary') : _('Edit supermaster'),
            'delete_action' => $modern ? _('Delete autoprimary') : _('Delete supermaster'),
            'about_title' => $modern ? _('About Autoprimaries') : _('About Supermasters'),
            'about_question' => $modern ? _('What is an Autoprimary?') : _('What is a Supermaster?'),
            'search_placeholder' => $modern ? _('Search autoprimaries...') : _('Search supermasters...'),
            'list_empty' => $modern
                ? _('There are no autoprimaries to show in this listing.')
                : _('There are no supermasters to show in this listing.'),
            'ip_label' => $modern ? _('IP address of autoprimary') : _('IP address of supermaster'),
            'account_help' => $modern
                ? _('Select the account that will own this autoprimary')
                : _('Select the account that will own this supermaster'),
            'about_edit_title' => $modern ? _('About Editing Autoprimaries') : _('About Editing Supermasters'),
            'edit_help_text' => $modern
                ? _('You can modify the IP address, hostname, or account for this autoprimary.')
                : _('You can modify the IP address, hostname, or account for this supermaster.'),
            'details_title' => $modern ? _('Autoprimary Details') : _('Supermaster Details'),
            'delete_warning' => $modern
                ? _('You are about to delete the autoprimary')
                : _('You are about to delete the supermaster'),
            'delete_yes' => $modern ? _('Yes, delete this autoprimary') : _('Yes, delete this supermaster'),
            'delete_no' => $modern ? _('No, keep this autoprimary') : _('No, keep this supermaster'),
            'connectivity_help' => $modern
                ? _('Showing connectivity status for the autoprimary servers configured in the table below.')
                : _('Showing connectivity status for the supermasters configured in the table below.'),
            default => $modern ? _('Autoprimaries') : _('Supermasters'),
        };
    }

    /**
     * Build a PdnsCapabilities snapshot from the session-cached PowerDNS
     * version. Indirected so tests can override behaviour by subclassing.
     */
    protected function resolveCapabilities(): PdnsCapabilities
    {
        $info = PdnsVersionService::getCachedInfo();
        return PdnsCapabilities::fromVersion($info['version'] ?? null);
    }
}
