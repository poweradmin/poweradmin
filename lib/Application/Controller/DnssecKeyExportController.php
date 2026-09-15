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

/**
 * Streams a DNSSEC private key as a PEM file download. Requires PowerDNS
 * 4.7+ which exposes the `privatekey` field on per-key GETs of /cryptokeys.
 *
 * Note: this controller returns the file directly and does NOT call render(),
 * so the HTML header/footer aren't emitted - on errors it redirects back to
 * the DNSSEC list page with a flash message.
 */
class DnssecKeyExportController extends DnssecKeyController
{
    public function run(): void
    {
        $zoneIdInt = $this->requireNumericParam('zone_id');
        $keyId = $this->requireNumericParam('key_id');
        $zoneId = (string)$zoneIdInt;
        [$domainName, $dnssecProvider] = $this->requireManagedDnssecZone($zoneIdInt);

        if (!$this->getPdnsCapabilities()->supportsPemKeyImportExport()) {
            $this->setMessage('dnssec', 'error', _('PEM key export requires PowerDNS 4.7 or newer.'));
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

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
