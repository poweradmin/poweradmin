<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\PermissionTemplateContentGuard;

class PermissionTemplateTypeTest extends TestCase
{
    public static function types(): array
    {
        return [
            'user' => ['user', true],
            'group' => ['group', true],
            'missing' => [null, false],
            'empty' => ['', false],
            'unknown' => ['admin', false],
            'wrong case' => ['User', false],
            'array' => [['user'], false],
        ];
    }

    #[DataProvider('types')]
    public function testOnlyUserAndGroupAreAccepted(mixed $type, bool $expected): void
    {
        $this->assertSame($expected, PermissionTemplateContentGuard::isValidTemplateType($type));
    }

    public function testBothControllersRefuseBeforeAnyWrite(): void
    {
        $root = dirname(__DIR__, 4) . '/lib/Application/Controller/';
        foreach (['EditPermTemplController' => 'updatePermissionTemplateDetails', 'AddPermTemplController' => 'addPermissionTemplate'] as $class => $write) {
            $src = (string)file_get_contents($root . $class . '.php');
            $check = strpos($src, 'isValidTemplateType');
            $this->assertNotFalse($check, $class);
            $this->assertLessThan(strpos($src, '->' . $write . '('), $check, $class);
        }
    }
}
