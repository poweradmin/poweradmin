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
 * Script that handles bulk record addition to an existing zone
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Exception;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\RecordTypeService;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;
use Symfony\Component\Validator\Constraints as Assert;

class BulkRecordAddController extends BaseController
{
    private LegacyLogger $logger;
    private DnsRecord $dnsRecord;
    private RecordManagerService $recordManager;
    private RecordTypeService $recordTypeService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->logger = new LegacyLogger($this->db);
        $this->dnsRecord = new DnsRecord($this->db, $this->getConfig());

        $recordCommentRepository = new DbRecordCommentRepository($this->db, $this->getConfig());
        $recordCommentService = new RecordCommentService($recordCommentRepository);
        $commentSyncService = new RecordCommentSyncService($recordCommentService);

        $this->recordManager = new RecordManagerService(
            $this->db,
            $this->dnsRecord,
            $recordCommentService,
            $commentSyncService,
            $this->logger,
            $this->getConfig()
        );

        $this->recordTypeService = new RecordTypeService($this->getConfig());
    }

    public function run(): void
    {
        $this->checkId();

        $perm_edit = Permission::getEditPermission($this->db);
        $zone_id = htmlspecialchars($_GET['id']);
        $zone_type = $this->dnsRecord->getDomainType($zone_id);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

        $this->checkCondition($zone_type == "SLAVE"
            || $perm_edit == "none"
            || ($perm_edit == "own" || $perm_edit == "own_as_client")
            && !$user_is_zone_owner, _('You do not have the permission to add records to this zone.'));

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->doBulkRecordAddition();
        } else {
            $this->showBulkRecordAdditionForm();
        }
    }

    private function doBulkRecordAddition(): void
    {
        $constraints = [
            'records' => [
                new Assert\NotBlank(message: _('Please provide at least one record.'))
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_POST)) {
            $this->showFirstValidationError($_POST);
        }

        $zone_id = (int)$_GET['id'];
        $records_text = $_POST['records'];
        $lines = explode("\n", trim($records_text));
        $default_ttl = $this->config->get('dns', 'ttl', 3600);

        $success_count = 0;
        $failed_records = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $parts = str_getcsv($line);

            // Expected format: name,type,content,priority,ttl
            if (count($parts) < 3) {
                $failed_records[] = $line . " - " . _('Invalid format. Expected at least: name,type,content');
                continue;
            }

            $name = trim($parts[0]);
            $type = strtoupper(trim($parts[1]));
            $content = trim($parts[2]);
            // Get the other fields, with special handling for SRV records
            if ($type === 'SRV' && count($parts) >= 5) {
                // For SRV records, parts[3] is typically the priority field (second number in the content)
                // This needs to be parsed differently to match PowerDNS expected format
                $prio = 0; // SRV priority is handled in content
                $ttl = isset($parts[4]) && $parts[4] !== '' ? (int)$parts[4] : $default_ttl;
                $comment = isset($parts[5]) ? trim($parts[5]) : '';

                // For SRV records, we need to reformat the content to be: weight port target
                // Example: sip.example.com.,0,5060 becomes "0 5060 sip.example.com."
                if (isset($parts[3]) && is_numeric($parts[3])) {
                    $weight = (int)$parts[3];
                    $port = isset($parts[4]) && is_numeric($parts[4]) ? (int)$parts[4] : 0;
                    $target = $content;

                    // Rebuild content in the format PowerDNS expects for SRV
                    $content = "$weight $port $target";

                    // Use the next field as TTL if provided
                    $ttl = isset($parts[5]) && is_numeric($parts[5]) ? (int)$parts[5] : $default_ttl;
                    $comment = isset($parts[6]) ? trim($parts[6]) : '';
                }
            } else {
                $prio = isset($parts[3]) && $parts[3] !== '' ? (int)$parts[3] : 0;
                $ttl = isset($parts[4]) && $parts[4] !== '' ? (int)$parts[4] : $default_ttl;
                $comment = isset($parts[5]) ? trim($parts[5]) : '';
            }

            // Restore full record name if using hostname-only display
            $display_hostname_only = $this->config->get('interface', 'display_hostname_only', false);
            $zone_name = $this->dnsRecord->getDomainNameById($zone_id);
            if ($display_hostname_only && $zone_name !== false) {
                $name = DnsHelper::restoreZoneSuffix($name, $zone_name);
            }

            // Validate record type
            $isReverseZone = DnsHelper::isReverseZone($zone_name);
            $isDnsSecEnabled = $this->config->get('dnssec', 'enabled', false);
            $valid_types = $isReverseZone ? $this->recordTypeService->getReverseZoneTypes($isDnsSecEnabled) : $this->recordTypeService->getDomainZoneTypes($isDnsSecEnabled);

            if (!in_array($type, $valid_types)) {
                $failed_records[] = $line . " - " . _('Invalid record type.');
                continue;
            }

            try {
                // For CNAME, MX, SRV, and similar records, ensure content ends with a dot
                if (in_array($type, ['CNAME', 'MX', 'SRV', 'NS']) && !empty($content) && !str_ends_with($content, '.')) {
                    $content .= '.';
                }

                // Handle apex zone records (@)
                if ($name === '@') {
                    // For @ records, use the zone name directly without appending anything
                    $name = '';
                } elseif (str_starts_with($name, '@.')) {
                    // If the name starts with @., remove it
                    $name = substr($name, 2);
                }

                if (
                    $this->recordManager->createRecord(
                        $zone_id,
                        $name,
                        $type,
                        $content,
                        $ttl,
                        $prio,
                        $comment,
                        $_SESSION['userlogin'],
                        $_SERVER['REMOTE_ADDR']
                    )
                ) {
                    $success_count++;

                    // Log the record creation
                    $this->logger->logInfo(sprintf(
                        'client_ip:%s user:%s operation:add_record name:%s type:%s content:%s ttl:%s prio:%s',
                        $_SERVER['REMOTE_ADDR'],
                        $_SESSION["userlogin"],
                        $name,
                        $type,
                        $content,
                        $ttl,
                        $prio
                    ), $zone_id);
                } else {
                    $failed_records[] = $line . " - " . _('Record could not be added.');
                }
            } catch (Exception $e) {
                $failed_records[] = $line . " - " . $e->getMessage();
            }
        }

        if (!$failed_records) {
            $this->setMessage('edit', 'success', sprintf(_('%d record(s) have been added successfully.'), $success_count));
            $this->redirect('/zones/' . $zone_id . '/edit');
        } else {
            $this->setMessage('bulk_record_add', 'warn', _('Some record(s) could not be added.'));
            $this->showBulkRecordAdditionForm($failed_records);
        }
    }

    private function showBulkRecordAdditionForm(array $failed_records = []): void
    {
        $zone_id = htmlspecialchars($_GET['id']);
        $zone_name = $this->dnsRecord->getDomainNameById($zone_id);

        // For internationalized domain names
        if (str_starts_with($zone_name, "xn--")) {
            $idn_zone_name = DnsIdnService::toUtf8($zone_name);
        } else {
            $idn_zone_name = "";
        }

        $this->render('bulk_record_add.html', [
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'failed_records' => $failed_records,
            'default_ttl' => $this->config->get('dns', 'ttl', 3600),
            'iface_record_comments' => $this->config->get('interface', 'show_record_comments', true),
            'is_reverse_zone' => $zone_name !== null && DnsHelper::isReverseZone($zone_name),
            'display_hostname_only' => $this->config->get('interface', 'display_hostname_only', false),
        ]);
    }

    public function checkId(): void
    {
        $constraints = [
            'id' => [
                new Assert\NotBlank(message: _('Zone ID is required.'))
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_GET)) {
            $this->showFirstValidationError($_GET);
        }
    }
}
