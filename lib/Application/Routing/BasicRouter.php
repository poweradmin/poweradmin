<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Application\Routing;

use Exception;

/**
 * Class BasicRouter
 *
 * BasicRouter is a simple router class that routes requests to the appropriate controller
 * based on the 'page' parameter in the request.
 *
 * @package Poweradmin\AppManager\Routing
 */
class BasicRouter
{
    /**
     * @var array $request The request parameters.
     */
    private array $request;

    /**
     * @var array $pages The list of valid page names.
     */
    private array $pages = [];

    /**
     * @var string $defaultPage The default page name.
     */
    private string $defaultPage;

    /**
     * BasicRouter constructor.
     *
     * @param array $request The request parameters.
     */
    public function __construct(array $request)
    {
        $this->request = $request;
    }

    /**
     * Get the page name from the request or return the default page.
     *
     * @return string The page name.
     */
    public function getPageName(): string
    {
        $page = $this->request['page'] ?? $this->defaultPage;
        $page = explode('?', $page)[0];

        if (in_array($page, $this->pages)) {
            return $page;
        }

        return $this->defaultPage;
    }

    /**
     * Get the fully qualified class name of the controller for the given page.
     *
     * @param string $page The page name.
     * @return string The controller class name.
     */
    public function getControllerClassName(string $page): string
    {
        $baseNamespace = '\Poweradmin\Application\Controller\\';
        $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $page)));
        return $baseNamespace . $className . 'Controller';
    }

    /**
     * Process the request by instantiating the appropriate controller and calling its run method.
     *
     * @return void
     * @throws Exception If the controller class does not exist.
     */
    public function process(): void
    {
        $pageName = $this->getPageName();
        $controllerClassName = $this->getControllerClassName($pageName);

        if (!class_exists($controllerClassName)) {
            throw new Exception("Class $controllerClassName not found");
        }

        $controller = new $controllerClassName($this->request);
        $controller->run();
    }

    /**
     * Set the list of valid page names.
     *
     * @param array $pages The list of valid page names.
     * @return void
     */
    public function setPages(array $pages): void
    {
        $this->pages = $pages;
    }

    /**
     * Set the default page name.
     *
     * @param string $string The default page name.
     * @return void
     */
    public function setDefaultPage(string $string): void
    {
        $this->defaultPage = $string;
    }
}