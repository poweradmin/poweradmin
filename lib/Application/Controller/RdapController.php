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

/**
 * Script that handles RDAP lookups
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\RdapService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;

class RdapController extends BaseController
{
    private RdapService $rdapService;
    private DnsRecord $dnsRecord;

    public function __construct(array $request, bool $authenticate = true)
    {
        parent::__construct($request, $authenticate);

        $this->rdapService = new RdapService();
        $this->dnsRecord = new DnsRecord($this->db, $this->config);

        // Set the request timeout from configuration
        $timeout = $this->config->get('rdap', 'request_timeout', 10);
        $this->rdapService->setRequestTimeout($timeout);
    }

    public function run(): void
    {
        // Check if RDAP functionality is enabled
        $rdap_enabled = $this->config->get('rdap', 'enabled', false);
        $this->checkCondition(!$rdap_enabled, _('RDAP lookup functionality is disabled.'));

        // Check if restricted to admin and enforce permission if needed
        $restrict_to_admin = $this->config->get('rdap', 'restrict_to_admin', true);
        if ($restrict_to_admin) {
            $this->checkPermission('user_is_ueberuser', _('You do not have permission to perform RDAP lookups.'));
        }

        $domain = $this->handleDomainInput();
        $result = $this->performRdapLookup($domain);

        $this->render('rdap.html', [
            'domain' => $domain,
            'utf8_domain' => preg_match('/^xn--/', $domain) ? DnsIdnService::toUtf8($domain) : $domain,
            'result' => $result,
            'custom_server' => $this->config->get('rdap', 'default_server', '')
        ]);
    }

    /**
     * Handles the domain input from various sources (direct input, zone ID)
     *
     * @return string The domain to lookup
     */
    private function handleDomainInput(): string
    {
        $domain = '';

        // Check if a domain was submitted through the form
        if ($this->isPost() && isset($this->getRequest()['domain'])) {
            $domain = trim($this->getRequest()['domain']);

            // Convert Unicode domain to Punycode if needed
            if (preg_match('/[^\x20-\x7E]/', $domain)) {
                $punycode = DnsIdnService::toPunycode($domain);
                if ($punycode !== false) {
                    $domain = $punycode;
                }
            }
        }
        // Check if a zone_id was provided
        elseif (isset($this->getRequest()['zone_id'])) {
            $zone_id = (int)$this->getRequest()['zone_id'];
            $domain = $this->dnsRecord->get_domain_name_by_id($zone_id);
        }

        return $domain;
    }

    /**
     * Performs the RDAP lookup for a domain
     *
     * @param string $domain The domain to lookup
     * @return array The RDAP lookup result
     */
    private function performRdapLookup(string $domain): array
    {
        $result = [
            'success' => false,
            'data' => null,
            'error' => null
        ];

        if (empty($domain)) {
            return $result;
        }

        // Check if we should use a custom default server
        $customServer = $this->config->get('rdap', 'default_server', '');

        if (!empty($customServer)) {
            // Use default server from config
            $response = $this->rdapService->query($domain, $customServer);

            if ($response !== null) {
                $result['success'] = true;
                $result['data'] = $this->rdapService->formatRdapResponse($response);
            } else {
                $result['error'] = sprintf(_('Failed to retrieve RDAP information using server %s'), $customServer);
            }
        } else {
            // Use automatic server detection
            $result = $this->rdapService->getRdapInfo($domain);
        }

        return $result;
    }
}
