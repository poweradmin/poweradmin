<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserMfa;

class UserMfaRecoveryCodeTest extends TestCase
{
    public function testNumericLookingCodeDoesNotMatchAnotherByLooseComparison(): void
    {
        $mfa = UserMfa::create(1, true, null, json_encode(['0e12345678']));

        // "0e0" == "0e12345678" is true in PHP, both being zero in scientific notation
        $this->assertFalse($mfa->validateRecoveryCode('0e0'));
        $this->assertSame(['0e12345678'], $mfa->getRecoveryCodesAsArray());
    }

    public function testExactCodeIsAcceptedOnceAndRemoved(): void
    {
        $mfa = UserMfa::create(1, true, null, json_encode(['abcd1234', 'ef567890']));

        $this->assertTrue($mfa->validateRecoveryCode('abcd1234'));
        $this->assertFalse($mfa->validateRecoveryCode('abcd1234'));
        $this->assertSame(['ef567890'], $mfa->getRecoveryCodesAsArray());
    }
}
