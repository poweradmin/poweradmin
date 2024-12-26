<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
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

use Poweradmin\AppConfiguration;
use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

class ReverseRecordCreator
{
    private PDOLayer $db;
    private AppConfiguration $config;
    private LegacyLogger $logger;
    private DnsRecord $dnsRecord;

    public function __construct(
        PDOLayer $db,
        AppConfiguration $config,
        LegacyLogger$logger,
        DnsRecord $dnsRecord
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
        $this->dnsRecord = $dnsRecord;
    }

    public function createReverseRecord($name, $type, $content, string $zone_id, $ttl, $prio, string $comment = '', string $account = ''): void
    {
        $iface_add_reverse_record = $this->config->get('iface_add_reverse_record');

        if ($name && $iface_add_reverse_record) {
            $content_rev = $this->getContentRev($type, $content);
            $zone_rev_id = $this->dnsRecord->get_best_matching_zone_id_from_name($content_rev);

            if ($zone_rev_id != -1) {
                $this->addReverseRecord($zone_id, $zone_rev_id, $name, $content_rev, $ttl, $prio, $comment, $account);
            } elseif (isset($content_rev)) {
                $this->presentError(sprintf(_('There is no matching reverse-zone for: %s.'), $content_rev));
            }
        }
    }

    private function getContentRev($type, $content): ?string
    {
        if ($type === 'A') {
            $content_array = preg_split("/\./", $content);
            return sprintf("%d.%d.%d.%d.in-addr.arpa", $content_array[3], $content_array[2], $content_array[1], $content_array[0]);
        } elseif ($type === 'AAAA') {
            return DnsRecord::convert_ipv6addr_to_ptrrec($content);
        }
        return null;
    }

    private function addReverseRecord($zone_id, $zone_rev_id, $name, $content_rev, $ttl, $prio, string $comment, string $account): void
    {
        $zone_name = $this->dnsRecord->get_domain_name_by_id($zone_id);
        $fqdn_name = sprintf("%s.%s", $name, $zone_name);

        if ($this->dnsRecord->add_record($zone_rev_id, $content_rev, 'PTR', $fqdn_name, $ttl, $prio)) {
            $this->logger->log_info(sprintf('client_ip:%s user:%s operation:add_record record_type:PTR record:%s content:%s ttl:%s priority:%s',
                $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                $content_rev, $fqdn_name, $ttl, $prio), $zone_id);

            if ($this->config->get('pdnssec_use')) {
                $dnssecProvider = DnssecProviderFactory::create($this->db, $this->config);
                $dnssecProvider->rectifyZone($zone_name);
            }
        }
    }

    private function presentError(string $message): void
    {
        $error = new ErrorMessage($message);
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);
    }
}