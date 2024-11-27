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
}
