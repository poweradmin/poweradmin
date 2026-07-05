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

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\PasswordEncryptionService;

class PasswordEncryptionServiceTest extends TestCase
{
    private PasswordEncryptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PasswordEncryptionService('test-session-key');
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $plaintext = 'secret-value-123';

        $this->assertSame($plaintext, $this->service->decrypt($this->service->encrypt($plaintext)));
    }

    public function testEncryptProducesDifferentCiphertextPerCall(): void
    {
        // A random IV must make identical plaintexts encrypt differently.
        $this->assertNotSame($this->service->encrypt('same'), $this->service->encrypt('same'));
    }

    public function testDecryptEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', $this->service->decrypt(''));
    }

    public function testDecryptInputWithoutSeparatorReturnsEmpty(): void
    {
        $this->assertSame('', $this->service->decrypt('not-a-valid-ciphertext'));
    }

    public function testDecryptInputWithInvalidIvReturnsEmpty(): void
    {
        $this->assertSame('', $this->service->decrypt('Zm9vYmFy:dG9vc2hvcnQ='));
    }

    public function testDecryptTamperedCiphertextReturnsEmpty(): void
    {
        $encrypted = $this->service->encrypt('secret-value-123');
        [, $iv] = explode(':', $encrypted, 2);

        $this->assertSame('', $this->service->decrypt('AAAAAAAAAAAAAAAAAAAAAA==:' . $iv));
    }

    public function testDecryptWithWrongKeyReturnsEmpty(): void
    {
        $encrypted = $this->service->encrypt('secret-value-123');
        $otherService = new PasswordEncryptionService('a-different-key');

        $this->assertSame('', $otherService->decrypt($encrypted));
    }

    public function testEncryptEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', $this->service->encrypt(''));
    }
}
