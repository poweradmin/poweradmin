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
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Validator;

/**
 * Streams a DNSSEC private key as an ISC (BIND "Private-key-format") file,
 * the format PowerDNS returns on per-key GETs of /cryptokeys.
 *
 * Note: this controller returns the file directly and does NOT call render(),
 * so the HTML header/footer aren't emitted - on errors it redirects back to
 * the DNSSEC list page with a flash message.
 */
class DnssecKeyExportController extends BaseController
{
    public function run(): void
    {
        $zoneId = $this->getSafeRequestValue('zone_id');
        $keyId = $this->getSafeRequestValue('key_id');
        if (!$zoneId || !Validator::isNumber($zoneId) || !$keyId || !Validator::isNumber($keyId)) {
            $this->showError(_('Invalid or unexpected input given.'));
            return;
        }

        $zoneIdInt = (int) $zoneId;
        $permView = Permission::getViewPermission($this->db);
        $userIsZoneOwner = $this->isZoneOwner($zoneIdInt);

        if ($permView === 'none' || ($permView === 'own' && !$userIsZoneOwner)) {
            $this->showError(_('You do not have permission to view this zone.'));
            return;
        }

        $domainRepository = $this->createDomainRepository();
        if (!$domainRepository->zoneIdExists($zoneIdInt)) {
            $this->showError(_('There is no zone with this ID.'));
            return;
        }

        // Exporting private key material requires DNSSEC management permission.
        if (!$this->createPermissionService()->canManageDnssecForZone($this->db, $this->getCurrentUserId(), $zoneIdInt)) {
            $this->showError(_('You do not have permission to manage DNSSEC for this zone.'));
            return;
        }

        $domainName = $domainRepository->getDomainNameById($zoneIdInt);
        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig(), null, $this->logger);

        if ($dnssecProvider->isZonePresigned($domainName)) {
            $this->setMessage('dnssec', 'error', _('This zone is presigned; DNSSEC keys are managed at the primary server.'));
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        try {
            $pem = $dnssecProvider->exportZoneKeyPem($domainName, (int) $keyId);
        } catch (Exception $e) {
            $this->logger->error('Exception exporting DNSSEC key: {error}', ['error' => $e->getMessage()]);
            $this->setMessage('dnssec', 'error', _('An error occurred while exporting the key: ') . $e->getMessage());
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        if ($pem === null) {
            $this->setMessage('dnssec', 'error', _('Could not retrieve the private key from PowerDNS.'));
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        // PowerDNS hands out the key in the ISC (BIND) format, which dnssec-keygen names .private
        $filename = sprintf('%s-key-%s.private', $domainName, $keyId);
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
        }
        echo $pem;
        if (!str_ends_with($pem, "\n")) {
            echo "\n";
        }
    }
}
