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

namespace Poweradmin\Application\Controller;

use Exception;
use Poweradmin\Application\Service\ChangeRequestMessages;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\BulkRecordParser;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\RecordTypeService;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Handles the bulk add-records form for a zone: parses one record per line and saves them as one changeset.
 */
class BulkRecordAddController extends BaseController
{
    private DomainRepositoryInterface $domainRepository;
    private RecordManagerService $recordManager;
    private RecordTypeService $recordTypeService;
    private UserContextService $userContextService;
    private PermissionService $permissionService;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->domainRepository = $this->createDomainRepository();
        $this->recordManager = $this->createRecordManagerService();
        $this->recordTypeService = new RecordTypeService($this->getConfig());
        $this->userContextService = new UserContextService();
        $this->permissionService = $this->createPermissionService();
    }

    public function run(): void
    {
        $this->checkId();

        $zone_id = (int)$this->getSafeRequestValue('id');
        $zone_type = $this->domainRepository->getDomainType($zone_id);
        $userId = $this->userContextService->getLoggedInUserId();

        $perm_edit = $this->permissionService->getEditPermissionLevelForZone($userId, $zone_id);

        // Bulk add stays direct-only: users whose changes need review use the zone editor
        $this->checkCondition(
            $this->changeApprovalModeForZone($zone_id) === ChangeApprovalPolicy::MODE_REQUEST,
            ChangeRequestMessages::requiresApproval()
        );
        $this->checkCondition(
            ZoneType::isReadOnly($zone_type) || $perm_edit === 'none',
            _('You do not have permission to add records to this zone.')
        );

        if ($this->isPost()) {
            $this->doBulkRecordAddition();
        } else {
            $this->showBulkRecordAdditionForm();
        }
    }

    private function doBulkRecordAddition(): void
    {
        $constraints = [
            'records' => [
                new Assert\NotBlank(message: _('Provide at least one record'))
            ]
        ];

        $this->setValidationConstraints($constraints);

        $postParams = $this->httpRequest->getPostParams();
        if (!$this->doValidateRequest($postParams)) {
            $this->showFirstValidationError($postParams);
        }

        $zone_id = (int)$this->getSafeRequestValue('id');

        $change_comment = (string)($this->httpRequest->getPostParam('change_comment') ?? '');
        if (trim($change_comment) === '' && RecordChangeLogger::changeCommentRequired()) {
            $this->setMessage('bulk_record_add', 'error', _('Describe why you are making this change.'));
            $this->showBulkRecordAdditionForm();
            return;
        }

        $records_text = $this->httpRequest->getPostParam('records');
        $lines = explode("\n", trim($records_text));

        $parser = new BulkRecordParser();
        $reverseTtlResolver = $this->services()->reverseTtlResolver();

        // One submission, one changeset: every record added below is grouped under a
        // single entry in the change log carrying the reason the user gave.
        [$success_count, $failed_records] = RecordChangeLogger::withChangeset($zone_id, $change_comment, function () use ($lines, $zone_id, $parser, $reverseTtlResolver): array {
            $success_count = 0;
            $failed_records = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $result = $parser->parseLine($line);
                if (is_string($result)) {
                    $failed_records[] = $line . " - " . $result;
                    continue;
                }

                $name = DnsIdnService::toPunycode($result['name']);
                $type = $result['type'];
                $content = $result['content'];
                $prio = $result['prio'];
                $ttl = $result['ttl'];
                $disabled = $result['disabled'];
                $comment = $result['comment'];

                // Convert IDN content to punycode after full content assembly
                $content = DnsIdnService::convertContentToPunycode($type, $content);

                // Normalize record name to full FQDN (always, regardless of display setting)
                // This converts @ to zone apex and ensures proper zone suffix
                $zone_name = $this->domainRepository->getDomainNameById($zone_id);
                if ($zone_name === null) {
                    $failed_records[] = $line . " - " . _('Zone not found.');
                    continue;
                }
                $name = DnsHelper::restoreZoneSuffix($name, $zone_name);

                // Validate record type. Filter by the connected server's
                // capabilities so bulk import matches the add/edit dropdowns -
                // otherwise users would get the same SVCB/HTTPS/WALLET line
                // accepted here and rejected by PowerDNS later.
                $isReverseZone = DnsHelper::isReverseZoneName($zone_name);
                $isDnsSecEnabled = $this->config->get('dnssec', 'enabled', false);
                $caps = $this->getRecordTypeCapabilities();
                $valid_types = $isReverseZone
                    ? $this->recordTypeService->getReverseZoneTypes($isDnsSecEnabled, $caps, false)
                    : $this->recordTypeService->getDomainZoneTypes($isDnsSecEnabled, $caps, false);

                if (!in_array($type, $valid_types)) {
                    $failed_records[] = $line . " - " . _('Invalid record type.');
                    continue;
                }

                // Apply the type-aware default when the bulk line omitted an explicit TTL.
                if ($ttl === null) {
                    $ttl = $reverseTtlResolver->resolveTtlForType($type, $isReverseZone);
                }

                try {
                    // For CNAME, MX, SRV, and similar records, ensure content ends with a dot
                    if (in_array($type, ['CNAME', 'MX', 'SRV', 'NS']) && !empty($content) && !str_ends_with($content, '.')) {
                        $content .= '.';
                    }

                    $result = $this->recordManager->createRecord(
                        $zone_id,
                        $name,
                        $type,
                        $content,
                        $ttl,
                        $prio,
                        $comment,
                        $this->userContextService->getLoggedInUsername(),
                        $disabled
                    );
                    if ($result->success) {
                        $success_count++;
                    } else {
                        $failed_records[] = $line . " - " . $result->message;
                    }
                } catch (Exception $e) {
                    $failed_records[] = $line . " - " . $e->getMessage();
                }
            }

            return [$success_count, $failed_records];
        });

        if (!$failed_records) {
            $this->setMessage('edit', 'success', sprintf(_('%d record(s) have been added successfully.'), $success_count));
            $this->redirect('/zones/' . $zone_id . '/edit');
        } else {
            $this->setMessage('bulk_record_add', 'warning', _('Some record(s) could not be added.'));
            $this->showBulkRecordAdditionForm($failed_records);
        }
    }

    private function showBulkRecordAdditionForm(array $failed_records = []): void
    {
        $zone_id = (int)$this->getSafeRequestValue('id');
        $zone_name = $this->domainRepository->getDomainNameById($zone_id);

        // For internationalized domain names
        $idn_zone_name = DnsIdnService::toIdnAlias($zone_name);

        $this->render('bulk_record_add.html', [
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'zone_display_name' => DnsIdnService::toDisplay($zone_name),
            'failed_records' => $failed_records,
            'change_comment' => (string)($this->httpRequest->getPostParam('change_comment') ?? ''),
            'require_change_comment' => (bool)$this->config->get('logging', 'require_change_comment', false),
            'default_ttl' => $this->config->get('dns', 'ttl', 3600),
            'iface_record_comments' => $this->config->get('interface', 'show_record_comments', true),
            'is_reverse_zone' => $zone_name !== null && DnsHelper::isReverseZoneName($zone_name),
            'display_hostname_only' => $this->createUserPreferenceService()->getDisplayHostnameOnly(
                $this->userContextService->getLoggedInUserId()
            ),
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

        if (!$this->doValidateRequest($this->requestData)) {
            $this->showFirstValidationError($this->requestData);
        }
    }
}
