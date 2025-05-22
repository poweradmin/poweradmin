<?php

namespace unit;

use Error;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Routing\BasicRouter;

class BasicRouterTest extends TestCase
{
    public function testPageNameIsReturnedWhenValid(): void
    {
        $router = new BasicRouter(['page' => 'valid_page']);
        $router->setPages(['valid_page']);

        $this->assertEquals('valid_page', $router->getPageName());
    }

    public function testDefaultRouteIsReturnedWhenPageIsInvalid(): void
    {
        $router = new BasicRouter(['page' => 'invalid_page']);
        $router->setPages(['valid_page']);
        $router->setDefaultPage('default_page');

        $this->assertEquals('default_page', $router->getPageName());
    }

    public function testControllerClassNameIsFormattedCorrectly(): void
    {
        $router = new BasicRouter(['page' => 'valid_page']);
        $router->setPages(['valid_page']);

        $this->assertEquals('\Poweradmin\Application\Controller\ValidPageController', $router->getControllerClassName('valid_page'));
    }

    public function testControllerClassNameIsFormattedCorrectlyWithUnderscores(): void
    {
        $router = new BasicRouter(['page' => 'valid_page_with_underscores']);
        $router->setPages(['valid_page_with_underscores']);

        $this->assertEquals('\Poweradmin\Application\Controller\ValidPageWithUnderscoresController', $router->getControllerClassName('valid_page_with_underscores'));
    }

    public function testPageNameIsReturnedWithoutQueryString(): void
    {
        $router = new BasicRouter(['page' => 'valid_page?param=value']);
        $router->setPages(['valid_page']);

        $this->assertEquals('valid_page', $router->getPageName());
    }

    public function testDefaultRouteIsReturnedWhenPageIsNotSet(): void
    {
        $router = new BasicRouter([]);
        $router->setPages(['valid_page']);
        $router->setDefaultPage('default_page');

        $this->assertEquals('default_page', $router->getPageName());
    }

    public function testNoPageNameAndNoDefaultPage(): void
    {
        $router = new BasicRouter([]);
        $router->setPages(['valid_page']);

        $this->expectException(Error::class);
        $router->getPageName();
    }

    public function testInvalidPageNameAndNoDefaultPage(): void
    {
        $router = new BasicRouter(['page' => 'invalid_page']);
        $router->setPages(['valid_page']);

        $this->expectException(Error::class);
        $router->getPageName();
    }

    public function testNonExistentControllerClass(): void
    {
        $router = new BasicRouter(['page' => 'non_existent_class']);
        $router->setPages(['non_existent_class']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Class \Poweradmin\Application\Controller\NonExistentClassController not found');
        $router->process();
    }

    public function testRestfulApiV1UserVerifyRoute(): void
    {
        $router = new BasicRouter(['page' => 'api/v1/user/verify']);

        $this->assertEquals('api/v1/user_verify', $router->getPageName());
        $this->assertEquals('\Poweradmin\Application\Controller\Api\v1\UserVerifyController', $router->getControllerClassName('api/v1/user_verify'));
    }

    public function testRestfulApiV1UsersRoute(): void
    {
        $router = new BasicRouter(['page' => 'api/v1/users']);

        $this->assertEquals('api/v1/users', $router->getPageName());
        $this->assertEquals('\Poweradmin\Application\Controller\Api\v1\UsersController', $router->getControllerClassName('api/v1/users'));
    }

    public function testRestfulApiV1UsersWithIdRoute(): void
    {
        $router = new BasicRouter(['page' => 'api/v1/users/123']);

        $this->assertEquals('api/v1/users', $router->getPageName());
        $this->assertEquals([
            'id' => 123
        ], $router->getPathParameters());
    }

    public function testRestfulApiV1ZonesRoute(): void
    {
        $router = new BasicRouter(['page' => 'api/v1/zones']);

        $this->assertEquals('api/v1/zones', $router->getPageName());
        $this->assertEquals('\Poweradmin\Application\Controller\Api\v1\ZonesController', $router->getControllerClassName('api/v1/zones'));
    }

    public function testRestfulApiV1ZonesWithIdRoute(): void
    {
        $router = new BasicRouter(['page' => 'api/v1/zones/456']);

        $this->assertEquals('api/v1/zones', $router->getPageName());
        $this->assertEquals([
            'id' => 456
        ], $router->getPathParameters());
    }

    public function testRestfulApiV1ZoneRecordsRoute(): void
    {
        $router = new BasicRouter(['page' => 'api/v1/zones/123/records']);

        $this->assertEquals('api/v1/zones_records', $router->getPageName());
        $this->assertEquals([
            'id' => 123
        ], $router->getPathParameters());
        $this->assertEquals('\Poweradmin\Application\Controller\Api\v1\ZonesRecordsController', $router->getControllerClassName('api/v1/zones_records'));
    }

    public function testRestfulApiV1ZoneRecordsWithIdRoute(): void
    {
        $router = new BasicRouter(['page' => 'api/v1/zones/123/records/789']);

        $this->assertEquals('api/v1/zones_records', $router->getPageName());
        $this->assertEquals([
            'id' => 123,
            'sub_id' => 789
        ], $router->getPathParameters());
    }
}
