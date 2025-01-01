<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

class Zone {

    private string $name;
    private bool $isSecured;
    private array $keys;

    public function __construct(string $name, bool $isSecured = false, array $keys = []) {
        $this->name = $name;
        $this->isSecured = $isSecured;
        $this->keys = $keys;
    }

    public function getName(): string {
        return $this->name;
    }

    public function isSecured(): bool {
        return $this->isSecured;
    }

    public function secure(): void {
        $this->isSecured = true;
    }

    public function unsecure(): void {
        $this->isSecured = false;
    }

    public function addKey(CryptoKey $key): void {
        $this->keys[] = $key;
    }

    public function removeKey(int $keyId): void {
        foreach ($this->keys as $index => $key) {
            if ($key->getId() === $keyId) {
                unset($this->keys[$index]);
                $this->keys = array_values($this->keys); // Re-index array
                break;
            }
        }
    }

    public function getKeys(): array {
        return $this->keys;
    }

    public function getKey(int $keyId): ?CryptoKey {
        foreach ($this->keys as $key) {
            if ($key->getId() === $keyId) {
                return $key;
            }
        }
        return null;
    }

    public function activateKey(int $keyId): void {
        $key = $this->getKey($keyId);
        $key?->activate();
    }

    public function deactivateKey(int $keyId): void {
        $key = $this->getKey($keyId);
        $key?->deactivate();
    }
}
