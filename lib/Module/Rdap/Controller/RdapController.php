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

namespace Poweradmin\Module\Rdap\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Module\Rdap\Service\RdapService;

class RdapController extends BaseController
{
    private RdapService $rdapService;
    private DnsRecord $dnsRecord;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->rdapService = new RdapService();
        $this->dnsRecord = new DnsRecord($this->db, $this->config);

        $timeout = $this->getModuleConfig('rdap', 'request_timeout', 10);
        $this->rdapService->setRequestTimeout($timeout);
    }

    public function run(): void
    {
        $restrict_to_admin = $this->getModuleConfig('rdap', 'restrict_to_admin', true);
        if ($restrict_to_admin) {
            $this->checkPermission('user_is_ueberuser', _('You do not have permission to perform RDAP lookups.'));
        }

        $this->setCurrentPage('module_rdap');
        $this->setPageTitle(_('RDAP'));

        $domain = $this->handleDomainInput();
        $result = $this->performRdapLookup($domain);

        $this->render('@rdap/rdap.html', [
            'domain' => $domain,
            'utf8_domain' => str_starts_with($domain, 'xn--') ? DnsIdnService::toUtf8($domain) : $domain,
            'result' => $result,
            'custom_server' => $this->getModuleConfig('rdap', 'default_server', '')
        ]);
    }

    private function handleDomainInput(): string
    {
        $domain = '';

        if ($this->isPost() && isset($this->getRequest()['domain'])) {
            $domain = trim($this->getRequest()['domain']);

            if (preg_match('/[^\x20-\x7E]/', $domain)) {
                $punycode = DnsIdnService::toPunycode($domain);
                if ($punycode !== false) {
                    $domain = $punycode;
                }
            }
        } elseif (isset($this->getRequest()['id'])) {
            $zone_id = (int)$this->getRequest()['id'];
            $domain = $this->dnsRecord->getDomainNameById($zone_id) ?? '';
        } elseif (isset($this->getRequest()['zone_id'])) {
            $zone_id = (int)$this->getRequest()['zone_id'];
            $domain = $this->dnsRecord->getDomainNameById($zone_id) ?? '';
        }

        return $domain;
    }

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

        $customServer = $this->getModuleConfig('rdap', 'default_server', '');

        if (!empty($customServer)) {
            $response = $this->rdapService->query($domain, $customServer);

            if ($response !== null) {
                $result['success'] = true;
                $result['data'] = $this->rdapService->formatRdapResponse($response);
            } else {
                $result['error'] = sprintf(_('Failed to retrieve RDAP information using server %s'), $customServer);
            }
        } else {
            $result = $this->rdapService->getRdapInfo($domain);
        }

        return $result;
    }
}
