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


namespace Poweradmin\Tests\Unit\Domain\Enum;

use DateTime;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Enum\ApiKeyStatus;
use Poweradmin\Domain\Model\ApiKey;

class ApiKeyStatusTest extends TestCase
{
    public function testOnlyActiveIsUsable(): void
    {
        $this->assertTrue(ApiKeyStatus::ACTIVE->isUsable());
        $this->assertFalse(ApiKeyStatus::DISABLED->isUsable());
        $this->assertFalse(ApiKeyStatus::EXPIRED->isUsable());
    }

    public function testActiveKey(): void
    {
        $key = $this->key(disabled: false, expiresAt: new DateTime('+1 day'));

        $this->assertSame(ApiKeyStatus::ACTIVE, $key->status());
        $this->assertTrue($key->isValid());
    }

    public function testKeyWithoutExpiryIsActive(): void
    {
        $this->assertSame(ApiKeyStatus::ACTIVE, $this->key(false, null)->status());
    }

    public function testExpiredKey(): void
    {
        $key = $this->key(disabled: false, expiresAt: new DateTime('-1 day'));

        $this->assertSame(ApiKeyStatus::EXPIRED, $key->status());
        $this->assertFalse($key->isValid());
    }

    public function testDisabledKey(): void
    {
        $key = $this->key(disabled: true, expiresAt: null);

        $this->assertSame(ApiKeyStatus::DISABLED, $key->status());
        $this->assertFalse($key->isValid());
    }

    /**
     * Both conditions can hold at once; disabled is reported, matching the
     * if/elseif the key list template has always used.
     */
    public function testDisabledWinsOverExpired(): void
    {
        $key = $this->key(disabled: true, expiresAt: new DateTime('-1 day'));

        $this->assertSame(ApiKeyStatus::DISABLED, $key->status());
    }

    public function testStatusIsSerialisedAlongsideTheLegacyBooleans(): void
    {
        $json = $this->key(disabled: true, expiresAt: null)->jsonSerialize();

        $this->assertSame('disabled', $json['status']);
        $this->assertTrue($json['disabled']);
        $this->assertFalse($json['isValid']);
    }

    private function key(bool $disabled, ?DateTime $expiresAt): ApiKey
    {
        return new ApiKey(
            name: 'test',
            secretKey: 'secret',
            disabled: $disabled,
            expiresAt: $expiresAt
        );
    }
}
