<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\BaseController;
use ReflectionClass;

/**
 * requestData is the merged GET+POST array, so any key can arrive as an array.
 * htmlspecialchars() rejects that, which used to surface as a 500 on every one of
 * the accessor's call sites, including the CSRF token read.
 */
class BaseControllerSafeRequestValueTest extends TestCase
{
    private function createController(array $requestData): BaseController
    {
        $controller = $this->getMockBuilder(BaseController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['run'])
            ->getMock();

        $property = (new ReflectionClass(BaseController::class))->getProperty('requestData');
        $property->setValue($controller, $requestData);

        return $controller;
    }

    public function testMissingKeyReturnsEmptyString(): void
    {
        $this->assertSame('', $this->createController([])->getSafeRequestValue('id'));
    }

    public function testStringValueIsEscaped(): void
    {
        $controller = $this->createController(['name' => '<script>"x"</script>']);

        $this->assertSame('&lt;script&gt;&quot;x&quot;&lt;/script&gt;', $controller->getSafeRequestValue('name'));
    }

    public function testArrayValueReturnsEmptyStringInsteadOfThrowing(): void
    {
        $controller = $this->createController(['_token' => ['x'], 'id' => [1, 2]]);

        $this->assertSame('', $controller->getSafeRequestValue('_token'));
        $this->assertSame('', $controller->getSafeRequestValue('id'));
    }

    public function testNestedArrayValueReturnsEmptyString(): void
    {
        $controller = $this->createController(['id' => ['a' => ['b' => 'c']]]);

        $this->assertSame('', $controller->getSafeRequestValue('id'));
    }

    public function testScalarNonStringValuesAreStillReturned(): void
    {
        $controller = $this->createController(['n' => 42, 'f' => 1.5, 'b' => true]);

        $this->assertSame('42', $controller->getSafeRequestValue('n'));
        $this->assertSame('1.5', $controller->getSafeRequestValue('f'));
        $this->assertSame('1', $controller->getSafeRequestValue('b'));
    }

    public function testNullValueReturnsEmptyString(): void
    {
        $this->assertSame('', $this->createController(['id' => null])->getSafeRequestValue('id'));
    }
}
