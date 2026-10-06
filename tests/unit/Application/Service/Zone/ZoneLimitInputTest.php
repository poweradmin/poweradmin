<?php

namespace Poweradmin\Tests\Unit\Application\Service\Zone;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Zone\ZoneLimitInput;
use Poweradmin\Application\Service\Zone\ZoneLimitMessages;

class ZoneLimitInputTest extends TestCase
{
    public static function formValues(): array
    {
        return [
            'absent' => [null, true, null],
            'empty' => ['', true, null],
            'blank' => ['  ', true, null],
            'zero' => ['0', true, 0],
            'number' => [' 25 ', true, 25],
            'leading zeros' => ['007', true, 7],
            'largest' => ['2147483647', true, 2147483647],
            'too large' => ['2147483648', false, null],
            'huge' => ['99999999999999999999', false, null],
            'negative' => ['-1', false, null],
            'decimal' => ['1.5', false, null],
            'text' => ['ten', false, null],
            'array' => [['1'], false, null],
        ];
    }

    #[DataProvider('formValues')]
    public function testFromForm(mixed $value, bool $valid, ?int $limit): void
    {
        $this->assertSame(['valid' => $valid, 'limit' => $limit], ZoneLimitInput::fromForm($value));
    }

    public static function jsonValues(): array
    {
        return [
            'null clears' => [null, true, null],
            'zero' => [0, true, 0],
            'number' => [10, true, 10],
            'too large' => [2147483648, false, null],
            'negative' => [-1, false, null],
            'numeric string' => ['10', false, null],
            'float' => [1.0, false, null],
            'bool' => [true, false, null],
        ];
    }

    #[DataProvider('jsonValues')]
    public function testFromJson(mixed $value, bool $valid, ?int $limit): void
    {
        $this->assertSame(['valid' => $valid, 'limit' => $limit], ZoneLimitInput::fromJson($value));
    }

    public function testBelowUsageWarnsOnlyWhenOwnedExceedsTheLimit(): void
    {
        $this->assertNull(ZoneLimitMessages::belowUsage('alice', 5, null));
        $this->assertNull(ZoneLimitMessages::belowUsage('alice', 5, 5));
        $this->assertStringContainsString('alice owns 6 zones', (string)ZoneLimitMessages::belowUsage('alice', 6, 5));
    }

    public function testRemaining(): void
    {
        $this->assertNull(ZoneLimitMessages::remaining(null));
        $this->assertStringContainsString('reached your zone limit', (string)ZoneLimitMessages::remaining(0));
        $this->assertSame('You can own 1 more zone.', ZoneLimitMessages::remaining(1));
        $this->assertSame('You can own 3 more zones.', ZoneLimitMessages::remaining(3));
    }
}
