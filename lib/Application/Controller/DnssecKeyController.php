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

use Poweradmin\BaseController;
use Poweradmin\Domain\Service\DnssecProviderInterface;

/**
 * Base for the pages that add, edit, toggle, delete, import and export DNSSEC keys: one shared gate.
 */
abstract class DnssecKeyController extends BaseController
{
    /**
     * Gates a key-management page: the zone must be visible to the user, exist, and grant
     * dnssec_manage; presigned zones are sent back to the DNSSEC page because their keys
     * live at the primary. Every failure ends the request, so callers only see success.
     *
     * @return array{0: string, 1: DnssecProviderInterface} The zone name and the provider
     */
    protected function requireManagedDnssecZone(int $zoneId): array
    {
        $this->requireZoneView($zoneId);

        $domainRepository = $this->createDomainRepository();
        if (!$domainRepository->zoneIdExists($zoneId)) {
            $this->showError(_('There is no zone with this ID.'));
        }

        if (!$this->createPermissionService()->canManageDnssecForZone($this->getCurrentUserId(), $zoneId)) {
            $this->showError(_('You do not have permission to manage DNSSEC for this zone.'));
        }

        $domainName = (string)$domainRepository->getDomainNameById($zoneId);
        $provider = $this->createDnssecProvider();

        if ($provider->isZonePresigned($domainName)) {
            $this->setMessage('dnssec', 'error', _('This zone is presigned; DNSSEC keys are managed at the primary server.'));
            $this->redirect('/zones/' . $zoneId . '/dnssec');
        }

        return [$domainName, $provider];
    }
}
