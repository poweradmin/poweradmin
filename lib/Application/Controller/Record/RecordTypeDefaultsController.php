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

namespace Poweradmin\Application\Controller\Record;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Repository\RecordTypeDefaultRepositoryInterface;

/**
 * Admin UI for managing per-record-type default TTLs. The repository drives
 * ReverseTtlResolver::resolveTtlForType(), so values entered here take effect
 * for every record-creation path (UI + API).
 */
class RecordTypeDefaultsController extends BaseController
{
    private const PAGE_KEY = 'record_type_defaults';
    private const MAX_TTL = 2147483647;

    public function run(): void
    {
        if (!$this->getUserContextService()->isAuthenticated()) {
            $this->showError(_('Not available for anonymous users.'));
            return;
        }

        $this->checkPermission(Permission::PERM_USER_IS_UEBERUSER, _('You do not have the permission to view this page.'));

        $this->setCurrentPage(self::PAGE_KEY);
        $this->setPageTitle(_('Default TTLs by record type'));

        $repository = $this->services()->recordTypeDefaultRepository();

        if ($this->isPost()) {
            $this->handlePost($repository);
            $this->redirect('/tools/record-type-defaults');
            return;
        }

        $stored = $repository->findAll();
        $rows = [];
        foreach ($this->managedRecordTypes() as $type) {
            $rows[] = [
                'type' => $type,
                'ttl' => $stored[$type] ?? null,
            ];
        }

        $this->render('record_type_defaults.html', [
            'rows' => $rows,
            'dns_ttl' => (int)$this->config->get('dns', 'ttl', 86400),
            'ttl_reverse' => $this->config->get('dns', 'ttl_reverse', null),
        ]);
    }

    private function handlePost(RecordTypeDefaultRepositoryInterface $repository): void
    {
        $submitted = $this->requestData['ttls'] ?? [];
        if (!is_array($submitted)) {
            $this->setMessage(self::PAGE_KEY, 'error', _('Invalid form submission.'));
            return;
        }

        if (!$repository->isReady()) {
            $this->setMessage(
                self::PAGE_KEY,
                'error',
                _('The record_type_defaults table is missing. Apply the 4.5.0 schema update before saving defaults.')
            );
            return;
        }

        $audit = $this->services()->auditService();

        $allowed = array_flip($this->managedRecordTypes());
        $saved = 0;
        $removed = 0;
        $rejected = [];
        foreach ($submitted as $type => $value) {
            $type = strtoupper((string)$type);
            if (!isset($allowed[$type])) {
                continue;
            }
            if (!is_string($value)) {
                $rejected[] = $type;
                continue;
            }
            if ($value === '') {
                $repository->delete($type);
                $audit->logRecordTypeDefaultDelete($type);
                $removed++;
                continue;
            }
            if (!preg_match('/^\d+$/', $value)) {
                $rejected[] = $type;
                continue;
            }
            $ttl = (int)$value;
            if ($ttl < 0 || $ttl > self::MAX_TTL) {
                $rejected[] = $type;
                continue;
            }
            $repository->save($type, $ttl);
            $audit->logRecordTypeDefaultSave($type, $ttl);
            $saved++;
        }

        if ($rejected !== []) {
            $this->setMessage(self::PAGE_KEY, 'warning', sprintf(
                _('Saved %d default(s); cleared %d. Rejected invalid values for: %s.'),
                $saved,
                $removed,
                implode(', ', $rejected)
            ));
            return;
        }

        $this->setMessage(self::PAGE_KEY, 'success', sprintf(
            _('Saved %d default(s); cleared %d.'),
            $saved,
            $removed
        ));
    }

    /**
     * The set of record types exposed in the UI. Limited to the common ones for
     * both forward and reverse zones so the page stays focused; admins running
     * exotic types can still create entries via SQL.
     *
     * @return list<string>
     */
    private function managedRecordTypes(): array
    {
        $types = array_unique(array_merge(
            RecordType::DOMAIN_ZONE_COMMON_RECORDS,
            RecordType::REVERSE_ZONE_COMMON_RECORDS
        ));
        sort($types);
        return $types;
    }
}
