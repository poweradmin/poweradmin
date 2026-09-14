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

namespace Poweradmin\Application\Service;

use Poweradmin\Domain\Service\ZoneSigningOutcome;
use Poweradmin\Domain\Service\ZoneSigningResult;

/**
 * Words a ZoneSigningService outcome for the web pages that sign or unsign
 * an existing zone.
 */
class ZoneSigningMessages
{
    /**
     * @return array{0: string, 1: string} Message type and text
     */
    public static function forSign(ZoneSigningResult $result): array
    {
        return match ($result->outcome) {
            ZoneSigningOutcome::SIGNED => ['success', _('Zone has been signed successfully.')],
            ZoneSigningOutcome::SERVER_DISABLED => ['error', _('DNSSEC is not enabled on the server.')],
            ZoneSigningOutcome::PRESIGNED => ['error', _('This zone is presigned; DNSSEC keys are managed at the primary server.')],
            ZoneSigningOutcome::ALREADY_SIGNED => ['info', _('Zone is already signed with DNSSEC.')],
            ZoneSigningOutcome::INVALID_ZONE => ['error', $result->detail],
            ZoneSigningOutcome::VERIFY_FAILED => ['warning', _('Zone signing requested successfully, but verification failed. Check DNSSEC keys.')],
            default => ['error', _('Failed to sign zone. Zone validation passed, but PowerDNS API returned an error. Check PowerDNS logs for details.')],
        };
    }

    /**
     * @return array{0: string, 1: string} Message type and text
     */
    public static function forUnsign(ZoneSigningResult $result): array
    {
        return match ($result->outcome) {
            ZoneSigningOutcome::UNSIGNED => ['success', _('Zone has been unsigned successfully.')],
            ZoneSigningOutcome::PRESIGNED => ['error', _('This zone is presigned; DNSSEC keys are managed at the primary server.')],
            ZoneSigningOutcome::NOT_SIGNED => ['info', _('Zone is not currently signed with DNSSEC.')],
            ZoneSigningOutcome::VERIFY_FAILED => ['warning', _('Zone unsigning requested successfully, but verification failed.')],
            default => ['error', _('Failed to unsign zone. Check PowerDNS logs for details.')],
        };
    }
}
