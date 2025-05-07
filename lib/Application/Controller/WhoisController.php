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
 * Script that handles WHOIS lookups
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\WhoisService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;

class WhoisController extends BaseController
{
    private WhoisService $whoisService;
    private DnsRecord $dnsRecord;

    public function __construct(array $request, bool $authenticate = true)
    {
        parent::__construct($request, $authenticate);

        $this->whoisService = new WhoisService();
        $this->dnsRecord = new DnsRecord($this->db, $this->config);

        // Set the socket timeout from configuration
        $timeout = $this->config->get('whois', 'socket_timeout', 10);
        $this->whoisService->setSocketTimeout($timeout);
    }

    public function run(): void
    {
        // Check if WHOIS functionality is enabled
        $whois_enabled = $this->config->get('whois', 'enabled', false);
        $this->checkCondition(!$whois_enabled, _('WHOIS lookup functionality is disabled.'));

        // Check if restricted to admin and enforce permission if needed
        $restrict_to_admin = $this->config->get('whois', 'restrict_to_admin', true);
        if ($restrict_to_admin) {
            $this->checkPermission('user_is_ueberuser', _('You do not have permission to perform WHOIS lookups.'));
        }

        $domain = $this->handleDomainInput();
        $result = $this->performWhoisLookup($domain);

        $this->render('whois.html', [
            'domain' => $domain,
            'utf8_domain' => preg_match('/^xn--/', $domain) ? DnsIdnService::toUtf8($domain) : $domain,
            'result' => $result,
            'custom_server' => $this->config->get('whois', 'default_server', '')
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
        } elseif (isset($this->getRequest()['zone_id'])) {
            $zone_id = (int)$this->getRequest()['zone_id'];
            $domain = $this->dnsRecord->getDomainNameById($zone_id);
        }

        return $domain;
    }

    /**
     * Performs the WHOIS lookup for a domain
     *
     * @param string $domain The domain to lookup
     * @return array The WHOIS lookup result
     */
    private function performWhoisLookup(string $domain): array
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
        $customServer = $this->config->get('whois', 'default_server', '');

        if (!empty($customServer)) {
            // Use default server from config
            $response = $this->whoisService->query($domain, $customServer);

            if ($response !== null) {
                $result['success'] = true;
                $result['data'] = $this->whoisService->formatWhoisResponse($response);
            } else {
                $result['error'] = sprintf(_('Failed to retrieve WHOIS information using server %s'), $customServer);
            }
        } else {
            // Use automatic server detection
            $result = $this->whoisService->getWhoisInfo($domain);
        }

        return $result;
    }
}
