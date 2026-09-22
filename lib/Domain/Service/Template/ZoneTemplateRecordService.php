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

use Exception;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DnsFormatter;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Adding, editing and deleting the records of a zone template. Writes report
 * their outcome instead of flashing it.
 */
class ZoneTemplateRecordService
{
    private ZoneTemplateRepositoryInterface $repository;
    private ZoneTemplateAccessPolicy $access;
    private ConfigurationInterface $config;
    private DnsBackendProviderInterface $backendProvider;
    private DnsFormatter $dnsFormatter;
    private ?ZoneTemplateRecordValidationService $recordValidationService = null;

    public function __construct(
        ZoneTemplateRepositoryInterface $repository,
        ZoneTemplateAccessPolicy $access,
        ConfigurationInterface $config,
        DnsBackendProviderInterface $backendProvider
    ) {
        $this->repository = $repository;
        $this->access = $access;
        $this->config = $config;
        $this->backendProvider = $backendProvider;
        $this->dnsFormatter = new DnsFormatter($config);
    }

    /**
     * Check a template record against the validator for its type.
     *
     * Built on demand because the validator registry instantiates every record-type
     * validator, which is wasted work on the paths that never store a record.
     */
    private function validateTemplateRecord(string $name, string $type, string $content, mixed $ttl, mixed $prio): ValidationResult
    {
        $this->recordValidationService ??= new ZoneTemplateRecordValidationService(
            new DnsValidatorRegistry($this->config, $this->backendProvider)
        );

        return $this->recordValidationService->validate(
            $name,
            $type,
            $content,
            $ttl,
            $prio,
            (int)$this->config->get('dns', 'ttl', 3600)
        );
    }

    /**
     * Add a record for a zone template
     *
     * This function validates and if correct it inserts it into the database.
     *
     * @param int $zone_templ_id zone template ID
     * @param string $name name part of record
     * @param string $type record type
     * @param string $content record content
     * @param int $ttl TTL
     * @param int $prio Priority
     */
    public function addZoneTemplRecord(int $zone_templ_id, string $name, string $type, string $content, int $ttl, int $prio): ZoneTemplateWriteResult
    {
        if (!$this->access->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT)) {
            return ZoneTemplateWriteResult::forbidden(_("You do not have the permission to add a record to this zone template."));
        }

        if (!$this->access->canStoreTemplateRecordType($type)) {
            return ZoneTemplateWriteResult::forbidden(_('You do not have the permission to add this record type to a zone template.'));
        }

        if ($content == '') {
            return ZoneTemplateWriteResult::failure(_('Your content field doesnt have a legit value.'));
        }

        if ($name == '') {
            return ZoneTemplateWriteResult::failure(_('Invalid hostname.'));
        }

        if (($prio < 0 || $prio > 65535) && RecordType::hasPriority($type)) {
            return ZoneTemplateWriteResult::failure(_('Priority for MX/SRV records must be a number between 0 and 65535.'));
        }

        // Add double quotes to content if it is a TXT record and dns_txt_auto_quote is enabled
        $content = $this->dnsFormatter->formatContent($type, $content);

        $validationResult = $this->validateTemplateRecord($name, $type, $content, $ttl, $prio);
        if (!$validationResult->isValid()) {
            return ZoneTemplateWriteResult::failure((string)$validationResult->getFirstError());
        }

        try {
            $this->repository->addRecord($zone_templ_id, $name, $type, $content, $ttl, $prio);
        } catch (Exception $e) {
            return ZoneTemplateWriteResult::backendFailure(_('Error adding zone template record: ') . $e->getMessage());
        }

        return ZoneTemplateWriteResult::ok();
    }

    /**
     * Modify zone template record
     *
     * @param array $record zone record array
     * @param int $zone_templ_id template the caller is authorized to edit
     */
    public function editZoneTemplRecord(array $record, int $zone_templ_id): ZoneTemplateWriteResult
    {
        if (!$this->access->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT)) {
            return ZoneTemplateWriteResult::forbidden(_("You do not have permission to edit this record."));
        }

        // Reject a record id that lives in another template, even when the caller owns this one.
        $storedRecord = $this->repository->getZoneTemplateRecordById((int)($record['rid'] ?? 0), $zone_templ_id);
        if (empty($storedRecord)) {
            return ZoneTemplateWriteResult::failure(_('The record does not belong to this zone template.'), Refusal::NOT_FOUND);
        }

        // Both types are gated: checking only the submitted one would let a
        // protected record be overwritten by relabelling it as an allowed type.
        if (
            !$this->access->canStoreTemplateRecordType((string)$storedRecord['type'])
            || !$this->access->canStoreTemplateRecordType($record['type'] ?? '')
        ) {
            return ZoneTemplateWriteResult::forbidden(_('You do not have the permission to add this record type to a zone template.'));
        }

        if ($record['name'] == "") {
            return ZoneTemplateWriteResult::failure(_('Invalid hostname.'));
        }

        if (
            (!is_numeric($record['prio']) || $record['prio'] < 0 || $record['prio'] > 65535)
            && RecordType::hasPriority((string)$record['type'])
        ) {
            return ZoneTemplateWriteResult::failure(_('Priority for MX/SRV records must be a number between 0 and 65535.'));
        }

        // Add double quotes to content if it is a TXT record and dns_txt_auto_quote is enabled
        $record['content'] = $this->dnsFormatter->formatContent($record['type'], $record['content']);

        $validationResult = $this->validateTemplateRecord(
            $record['name'],
            $record['type'],
            $record['content'],
            $record['ttl'],
            $record['prio'] ?? 0
        );
        if (!$validationResult->isValid()) {
            return ZoneTemplateWriteResult::failure((string)$validationResult->getFirstError());
        }

        try {
            $this->repository->updateRecord(
                (int)$record['rid'],
                (string)$record['name'],
                (string)$record['type'],
                (string)$record['content'],
                (int)$record['ttl'],
                (int)($record['prio'] ?? 0)
            );
        } catch (Exception $e) {
            return ZoneTemplateWriteResult::backendFailure(_('Error updating zone template record: ') . $e->getMessage());
        }

        return ZoneTemplateWriteResult::ok();
    }

    /**
     * Delete a record for a zone template by a given id
     *
     * @param int $rid template record id
     * @param int $zone_templ_id template the caller is authorized to edit
     */
    public function deleteZoneTemplRecord(int $rid, int $zone_templ_id): ZoneTemplateWriteResult
    {
        if (!$this->access->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT)) {
            return ZoneTemplateWriteResult::forbidden(_("You do not have the permission to delete this record."));
        }

        // Reject a record id that lives in another template, even when the caller owns this one.
        $storedRecord = $this->repository->getZoneTemplateRecordById($rid, $zone_templ_id);
        if (empty($storedRecord)) {
            return ZoneTemplateWriteResult::failure(_('The record does not belong to this zone template.'), Refusal::NOT_FOUND);
        }

        // A caller who may not create this type may not remove one either.
        if (!$this->access->canStoreTemplateRecordType((string)$storedRecord['type'])) {
            return ZoneTemplateWriteResult::forbidden(_('You do not have the permission to delete this record type from a zone template.'));
        }

        try {
            $this->repository->deleteRecord($rid);
        } catch (Exception $e) {
            return ZoneTemplateWriteResult::backendFailure(_('Error deleting zone template record: ') . $e->getMessage());
        }

        return ZoneTemplateWriteResult::ok();
    }
}
