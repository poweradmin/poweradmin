<?php

declare(strict_types=1);

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\BaseController;
use ReflectionClass;

class BaseControllerPageTitleTest extends TestCase
{
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reflection = new ReflectionClass(BaseController::class);
    }

    private function createController(): BaseController
    {
        return $this->getMockBuilder(BaseController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['run'])
            ->getMock();
    }

    public function testPageTitleDefaultsToEmptyString(): void
    {
        $controller = $this->createController();
        $property = $this->reflection->getProperty('pageTitle');

        $this->assertSame('', $property->getValue($controller));
    }

    public function testSetPageTitleStoresValue(): void
    {
        $controller = $this->createController();
        $method = $this->reflection->getMethod('setPageTitle');
        $property = $this->reflection->getProperty('pageTitle');

        $method->invoke($controller, 'Edit User');

        $this->assertSame('Edit User', $property->getValue($controller));
    }

    public function testSetPageTitleWithEmptyString(): void
    {
        $controller = $this->createController();
        $method = $this->reflection->getMethod('setPageTitle');
        $property = $this->reflection->getProperty('pageTitle');

        $method->invoke($controller, 'Initial Title');
        $method->invoke($controller, '');

        $this->assertSame('', $property->getValue($controller));
    }

    public function testSetPageTitleWithSpecialCharacters(): void
    {
        $controller = $this->createController();
        $method = $this->reflection->getMethod('setPageTitle');
        $property = $this->reflection->getProperty('pageTitle');

        $title = 'Zone: example.com (Edit) & <Records>';
        $method->invoke($controller, $title);

        $this->assertSame($title, $property->getValue($controller));
    }

    public function testSetPageTitleOverwritesPreviousValue(): void
    {
        $controller = $this->createController();
        $method = $this->reflection->getMethod('setPageTitle');
        $property = $this->reflection->getProperty('pageTitle');

        $method->invoke($controller, 'First Title');
        $method->invoke($controller, 'Second Title');

        $this->assertSame('Second Title', $property->getValue($controller));
    }

    public function testSetCurrentPageStoresValue(): void
    {
        $controller = $this->createController();
        $method = $this->reflection->getMethod('setCurrentPage');
        $property = $this->reflection->getProperty('requestData');

        $property->setValue($controller, []);
        $method->invoke($controller, 'edit_user');

        $this->assertSame('edit_user', $property->getValue($controller)['page']);
    }

    public function testSetCurrentPageOverwritesPreviousValue(): void
    {
        $controller = $this->createController();
        $method = $this->reflection->getMethod('setCurrentPage');
        $property = $this->reflection->getProperty('requestData');

        $property->setValue($controller, ['page' => 'users']);
        $method->invoke($controller, 'edit_user');

        $this->assertSame('edit_user', $property->getValue($controller)['page']);
    }
}
