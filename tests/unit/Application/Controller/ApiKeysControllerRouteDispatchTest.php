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
 */

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\ApiKeysController;
use ReflectionClass;

/**
 * The controller dispatches on the matched route name so each action stays on the
 * verbs its route declares. Dispatching on anything a client can set would let a
 * GET-only route reach a POST-only action.
 */
class ApiKeysControllerRouteDispatchTest extends TestCase
{
    private function actionFor(string $routeName): ?string
    {
        $reflection = new ReflectionClass(ApiKeysController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('getActionFromRoute');
        $method->setAccessible(true);

        return $method->invoke($controller, $routeName);
    }

    public function testEveryApiKeyRouteMapsToItsAction(): void
    {
        $this->assertSame('list', $this->actionFor('api_keys_list'));
        $this->assertSame('add', $this->actionFor('api_keys_add'));
        $this->assertSame('edit', $this->actionFor('api_keys_edit'));
        $this->assertSame('delete', $this->actionFor('api_keys_delete'));
        $this->assertSame('regenerate', $this->actionFor('api_keys_regenerate'));
        $this->assertSame('toggle', $this->actionFor('api_keys_toggle'));
    }

    /**
     * setCurrentPage() overwrites requestData['page'] with 'api_keys' before run()
     * reads the action, which is why the route name is captured in the constructor.
     * If it ever regresses to reading the live value, every action but list breaks.
     */
    public function testNonRouteNamesDoNotMapToAnAction(): void
    {
        $this->assertNull($this->actionFor('api_keys'));
        $this->assertNull($this->actionFor(''));
        $this->assertNull($this->actionFor('toggle'));
    }
}
