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

namespace Poweradmin\Domain\Model;

class CryptoKey
{

    private ?int $id;
    private ?string $type;
    private ?int $size;
    private ?string $algorithm;
    private bool $isActive;
    private ?string $dnskey;
    private array $ds;

    public function __construct(
        ?int $id,
        ?string $type = null,
        ?int $size = null,
        ?string $algorithm = null,
        bool $isActive = false,
        ?string $dnskey = null,
        ?array $ds = null
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->size = $size;
        $this->algorithm = $algorithm;
        $this->isActive = $isActive;
        $this->dnskey = $dnskey;
        $this->ds = $ds ?? [];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getAlgorithm(): ?string
    {
        return $this->algorithm;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function activate(): void
    {
        $this->isActive = true;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    public function getDnskey(): ?string
    {
        return $this->dnskey;
    }

    public function getDs(): array
    {
        return $this->ds;
    }

    /**
     * The algorithm number from the DNSKEY record, or 0 without a readable one.
     */
    public function getAlgorithmId(): int
    {
        $fields = $this->dnskeyFields();

        return count($fields) >= 3 ? (int)$fields[2] : 0;
    }

    /**
     * The key tag of the DNSKEY record (RFC 4034, appendix B), or 0 without a
     * readable one. Computed rather than read off a DS record, which a ZSK lacks.
     */
    public function getKeyTag(): int
    {
        $fields = $this->dnskeyFields();
        if (count($fields) < 4) {
            return 0;
        }

        $publicKey = base64_decode(implode('', array_slice($fields, 3)), true);
        if ($publicKey === false) {
            return 0;
        }

        // RFC 4034 appendix B.1: RSA/MD5 takes the tag from the modulus, not the checksum
        if ((int)$fields[2] === 1) {
            $length = strlen($publicKey);
            return $length >= 3 ? (ord($publicKey[$length - 3]) << 8) | ord($publicKey[$length - 2]) : 0;
        }

        $rdata = pack('nCC', (int)$fields[0], (int)$fields[1], (int)$fields[2]) . $publicKey;
        $sum = 0;
        $length = strlen($rdata);
        for ($i = 0; $i < $length; $i++) {
            $sum += ($i & 1) ? ord($rdata[$i]) : ord($rdata[$i]) << 8;
        }
        $sum += ($sum >> 16) & 0xFFFF;

        return $sum & 0xFFFF;
    }

    /**
     * @return list<string> Flags, protocol, algorithm and the public key chunks
     */
    private function dnskeyFields(): array
    {
        $dnskey = trim((string)$this->dnskey);

        return $dnskey === '' ? [] : (preg_split('/\s+/', $dnskey) ?: []);
    }
}
