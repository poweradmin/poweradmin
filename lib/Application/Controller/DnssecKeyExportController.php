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
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\Validator;

/**
 * Streams a DNSSEC private key as a PEM file download. Requires PowerDNS
 * 4.7+ which exposes the `privatekey` field on per-key GETs of /cryptokeys.
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
        $permEdit = Permission::getEditPermission($this->db);
        $userIsZoneOwner = UserManager::verifyUserIsOwnerZoneId($this->db, $zoneIdInt);

        if ($permView === 'none' || ($permView === 'own' && !$userIsZoneOwner)) {
            $this->showError(_('You do not have permission to view this zone.'));
            return;
        }

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        if (!$dnsRecord->zoneIdExists($zoneIdInt)) {
            $this->showError(_('There is no zone with this ID.'));
            return;
        }

        // Exporting private key material is an edit-equivalent operation.
        if ($permEdit === 'none' || ($permEdit === 'own' && !$userIsZoneOwner)) {
            $this->showError(_('You do not have permission to manage DNSSEC for this zone.'));
            return;
        }

        if (!$this->getPdnsCapabilities()->supportsPemKeyImportExport()) {
            $this->setMessage('dnssec', 'error', _('PEM key export requires PowerDNS 4.7 or newer.'));
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        $domainName = $dnsRecord->getDomainNameById($zoneIdInt);
        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

        try {
            $pem = $dnssecProvider->exportZoneKeyPem($domainName, (int) $keyId);
        } catch (Exception $e) {
            $this->logger->error('Exception exporting DNSSEC PEM key: {error}', ['error' => $e->getMessage()]);
            $this->setMessage('dnssec', 'error', _('An error occurred while exporting the PEM key: ') . $e->getMessage());
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        if ($pem === null) {
            $this->setMessage('dnssec', 'error', _('Could not retrieve the PEM key. The server may not expose private key material for this key.'));
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        $filename = sprintf('%s-key-%s.pem', $domainName, $keyId);
        if (!headers_sent()) {
            header('Content-Type: application/x-pem-file');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
        }
        echo $pem;
        if (!str_ends_with($pem, "\n")) {
            echo "\n";
        }
    }
}
