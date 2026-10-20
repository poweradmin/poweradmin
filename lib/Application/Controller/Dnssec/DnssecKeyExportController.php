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

namespace Poweradmin\Application\Controller\Dnssec;

use Exception;

/**
 * Streams a DNSSEC private key as an ISC (BIND "Private-key-format") file,
 * the format PowerDNS returns on per-key GETs of /cryptokeys.
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

        try {
            $privateKey = $dnssecProvider->exportZonePrivateKey($domainName, (int) $keyId);
        } catch (Exception $e) {
            $this->logger->error('Exception exporting DNSSEC key: {error}', ['error' => $e->getMessage()]);
            $this->setMessage('dnssec', 'error', _('An error occurred while exporting the key: ') . $e->getMessage());
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        if ($privateKey === null) {
            $this->setMessage('dnssec', 'error', _('Could not retrieve the private key from PowerDNS.'));
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        $filename = sprintf('%s-key-%s.private', $domainName, $keyId);
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
        }
        echo $privateKey;
        if (!str_ends_with($privateKey, "\n")) {
            echo "\n";
        }
    }
}
