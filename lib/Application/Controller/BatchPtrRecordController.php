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

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\BatchReverseRecordCreator;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Symfony\Component\Validator\Constraints as Assert;

class BatchPtrRecordController extends BaseController
{
    private LegacyLogger $logger;
    private DnsRecord $dnsRecord;
    private BatchReverseRecordCreator $batchReverseRecordCreator;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->logger = new LegacyLogger($this->db);
        $this->dnsRecord = new DnsRecord($this->db, $this->getConfig());

        $reverseRecordCreator = new ReverseRecordCreator(
            $this->db,
            $this->getConfig(),
            $this->logger,
            $this->dnsRecord
        );

        $this->batchReverseRecordCreator = new BatchReverseRecordCreator(
            $this->db,
            $this->getConfig(),
            $this->logger,
            $this->dnsRecord,
            $reverseRecordCreator
        );
    }

    public function run(): void
    {
        // Check if batch PTR records are enabled
        $isReverseRecordAllowed = $this->config->get('interface', 'add_reverse_record', true);
        $this->checkCondition(!$isReverseRecordAllowed, _("Batch PTR record creation is not enabled."));

        // Check if user has permission to use this feature
        $perm_edit_own = UserManager::verify_permission($this->db, 'zone_content_edit_own');
        $perm_edit_others = UserManager::verify_permission($this->db, 'zone_content_edit_others');
        $this->checkCondition(
            !$perm_edit_own && !$perm_edit_others,
            _("You do not have permission to edit DNS records.")
        );

        // Check if we have a specific zone_id
        $hasZoneId = isset($_GET['id']) && !empty($_GET['id']);

        if ($hasZoneId) {
            $this->checkId();
            $zone_id = htmlspecialchars($_GET['id']);
            $zone_type = $this->dnsRecord->get_domain_type($zone_id);
            $zone_name = $this->dnsRecord->get_domain_name_by_id($zone_id);
            $perm_edit = Permission::getEditPermission($this->db);
            $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $zone_id);

            // Check if this is a reverse zone
            $isReverseZone = DnsHelper::isReverseZone($zone_name);
            $this->checkCondition($isReverseZone, _("Batch PTR record creation is not available for reverse zones."));

            // Only check permissions if accessing from a specific zone
            $this->checkCondition($zone_type == "SLAVE"
                || $perm_edit == "none"
                || ($perm_edit == "own" || $perm_edit == "own_as_client")
                && !$user_is_zone_owner, _("You do not have the permission to add records to this zone."));
        }

        // Preserve form data in case of errors
        $formData = [];
        if ($this->isPost()) {
            $formData = $_POST;
            try {
                $this->validateCsrfToken();
                if ($this->addBatchPtrRecords()) {
                    // Clear form data on success
                    $formData = [];
                }
            } catch (\Exception $e) {
                $this->setMessage('batch_ptr_record', 'error', $e->getMessage());
                // Keep form data in case of error
            }
        }

        $this->showForm($formData);
    }

    private function addBatchPtrRecords(): bool
    {
        $constraints = [
            'network_type' => [
                new Assert\NotBlank()
            ],
            'network_prefix' => [
                new Assert\NotBlank()
            ],
            'domain' => [
                new Assert\NotBlank()
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_POST)) {
            $this->showFirstValidationError($_POST);
            return false;
        }

        $networkType = $_POST['network_type'] ?? '';
        $networkPrefix = $_POST['network_prefix'] ?? '';
        $hostPrefix = $_POST['host_prefix'] ?? '';
        $domain = $_POST['domain'] ?? '';
        $ttl = $this->config->get('dns', 'ttl', 86400);
        $prio = 0;
        $comment = $_POST['comment'] ?? '';
        $zone_id = isset($_GET['id']) ? (int)$_GET['id'] : 0; // Use 0 when no zone_id is provided
        $ipv6_count = isset($_POST['ipv6_count']) ? (int)$_POST['ipv6_count'] : 256;
        $createForwardRecords = isset($_POST['create_forward_records']) && $_POST['create_forward_records'] === 'on';

        try {
            if ($networkType === 'ipv4') {
                $result = $this->batchReverseRecordCreator->createIPv4Network(
                    $networkPrefix,
                    $hostPrefix,
                    $domain,
                    (string)$zone_id,
                    $ttl,
                    $prio,
                    $comment,
                    $_SESSION['userlogin'] ?? '',
                    $createForwardRecords
                );
            } else { // IPv6
                $result = $this->batchReverseRecordCreator->createIPv6Network(
                    $networkPrefix,
                    $hostPrefix,
                    $domain,
                    (string)$zone_id,
                    $ttl,
                    $prio,
                    $comment,
                    $_SESSION['userlogin'] ?? '',
                    $ipv6_count,
                    $createForwardRecords
                );
            }

            if ($result['success']) {
                $this->setMessage('batch_ptr_record', 'success', $result['message']);
                return true;
            } else {
                $this->setMessage('batch_ptr_record', 'error', $result['message']);
                return false;
            }
        } catch (\Exception $e) {
            $this->setMessage('batch_ptr_record', 'error', $e->getMessage());
            return false;
        }
    }

    private function showForm(array $formData = []): void
    {
        $hasZoneId = isset($_GET['id']) && !empty($_GET['id']);
        $file_version = time();
        $zone_id = "";
        $zone_name = "";
        $idn_zone_name = "";
        $isReverseZone = false;
        $preFillDomain = "";

        if ($hasZoneId) {
            $zone_id = htmlspecialchars($_GET['id']);
            $zone_name = $this->dnsRecord->get_domain_name_by_id($zone_id);
            $isReverseZone = DnsHelper::isReverseZone($zone_name);
            $preFillDomain = $zone_name;

            if (str_starts_with($zone_name, "xn--")) {
                $idn_zone_name = DnsIdnService::toUtf8($zone_name);
            } else {
                $idn_zone_name = "";
            }
        }

        $this->render('batch_ptr_record.html', [
            'network_type' => $formData['network_type'] ?? 'ipv4',
            'network_prefix' => $formData['network_prefix'] ?? '',
            'host_prefix' => $formData['host_prefix'] ?? '',
            'domain' => $formData['domain'] ?? $preFillDomain,
            'ttl' => $this->config->get('dns', 'ttl', 86400),
            'ipv6_count' => $formData['ipv6_count'] ?? 256,
            'comment' => $formData['comment'] ?? '',
            'create_forward_records' => $formData['create_forward_records'] ?? '',
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'is_reverse_zone' => $isReverseZone,
            'has_zone_id' => $hasZoneId,
            'file_version' => $file_version,
            'iface_record_comments' => $this->config->get('interface', 'show_record_comments', false),
        ]);
    }

    public function checkId(): void
    {
        $constraints = [
            'id' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_GET)) {
            $this->showFirstValidationError($_GET);
        }
    }
}
