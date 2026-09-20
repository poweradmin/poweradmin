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

namespace Poweradmin\Domain\Service;

use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Psr\Log\LoggerInterface;

/**
 * Signs and unsigns zones the one way every entry point agrees on: refuse
 * presigned zones and invalid zones, bump the SOA serial so secondaries
 * notice, verify what PowerDNS reports afterwards, rectify, and audit.
 */
class ZoneSigningService
{
    public function __construct(
        private readonly DnssecProviderInterface $dnssec,
        private readonly ZoneValidationService $validator,
        private readonly SOARecordManagerInterface $soaRecordManager,
        private readonly AuditLoggerInterface $audit,
        private readonly ConfigurationInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function sign(int $zoneId, string $zoneName): ZoneSigningResult
    {
        if (!$this->dnssec->isDnssecEnabled()) {
            return new ZoneSigningResult(ZoneSigningOutcome::SERVER_DISABLED);
        }
        if ($this->dnssec->isZonePresigned($zoneName)) {
            return new ZoneSigningResult(ZoneSigningOutcome::PRESIGNED);
        }
        if ($this->zoneIsSecured($zoneName)) {
            return new ZoneSigningResult(ZoneSigningOutcome::ALREADY_SIGNED);
        }

        // An invalid zone is refused before the serial moves.
        $validation = $this->validator->validateZoneForDnssec($zoneId, $zoneName);
        if (!$validation['valid']) {
            $this->logger->warning('DNSSEC pre-flight validation failed for zone: {zone}', ['zone' => $zoneName]);
            return new ZoneSigningResult(ZoneSigningOutcome::INVALID_ZONE, $this->validator->getFormattedErrorMessage($validation));
        }

        $this->soaRecordManager->updateSOASerial($zoneId);

        if (!$this->dnssec->secureZone($zoneName)) {
            $this->logger->error('DNSSEC signing failed for zone: {zone}', ['zone' => $zoneName]);
            return new ZoneSigningResult(ZoneSigningOutcome::SECURE_FAILED);
        }
        // isZoneSecured() reports false on API errors too, so the provider's own result is checked first.
        if (!$this->zoneIsSecured($zoneName)) {
            $this->logger->warning('DNSSEC signing verification failed for zone: {zone} - API returned success but zone not secured', ['zone' => $zoneName]);
            return new ZoneSigningResult(ZoneSigningOutcome::VERIFY_FAILED);
        }

        $this->dnssec->rectifyZone($zoneName);
        $this->audit->logDnssecSignZone($zoneId, $zoneName);

        return new ZoneSigningResult(ZoneSigningOutcome::SIGNED);
    }

    /**
     * Asked again after each write, since the answer is PowerDNS state.
     *
     * @phpstan-impure
     */
    private function zoneIsSecured(string $zoneName): bool
    {
        return $this->dnssec->isZoneSecured($zoneName, $this->config);
    }

    public function unsign(int $zoneId, string $zoneName): ZoneSigningResult
    {
        if ($this->dnssec->isZonePresigned($zoneName)) {
            return new ZoneSigningResult(ZoneSigningOutcome::PRESIGNED);
        }
        if (!$this->zoneIsSecured($zoneName)) {
            return new ZoneSigningResult(ZoneSigningOutcome::NOT_SIGNED);
        }

        if (!$this->dnssec->unsecureZone($zoneName)) {
            $this->logger->error('DNSSEC unsigning failed for zone: {zone}', ['zone' => $zoneName]);
            return new ZoneSigningResult(ZoneSigningOutcome::UNSECURE_FAILED);
        }
        if ($this->zoneIsSecured($zoneName)) {
            $this->logger->warning('DNSSEC unsigning verification failed for zone: {zone} - API returned success but zone still secured', ['zone' => $zoneName]);
            return new ZoneSigningResult(ZoneSigningOutcome::VERIFY_FAILED);
        }

        $this->soaRecordManager->updateSOASerial($zoneId);
        $this->audit->logDnssecUnsignZone($zoneId, $zoneName);

        return new ZoneSigningResult(ZoneSigningOutcome::UNSIGNED);
    }
}
