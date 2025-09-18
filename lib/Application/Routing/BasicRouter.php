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

use Error;
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
     * @var string|null $defaultPage The default page name.
     */
    private ?string $defaultPage = null;

    /**
     * @var array $pathParameters Extracted path parameters from RESTful routes.
     */
    private array $pathParameters = [];

    /**
     * @var bool $routeFound Whether the requested route was found.
     */
    private bool $routeFound = true;

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
        $page = $this->request['page'] ?? null;

        // If no page is provided, go to default page
        if ($page === null) {
            if ($this->defaultPage !== null) {
                $this->routeFound = true;
                return $this->defaultPage;
            } else {
                // No page and no default page configured
                throw new Error('No page specified and no default page configured');
            }
        }

        $page = explode('?', $page)[0];

        // Handle RESTful API routes
        if (str_starts_with($page, 'api/')) {
            return $this->processRestfulRoute($page);
        }

        if (in_array($page, $this->pages)) {
            $this->routeFound = true;
            return $page;
        }

        // Bad/wrong page provided - show 404
        $this->routeFound = false;
        return '404';
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

        // Special handling for 404 page
        if ($page === '404') {
            return $baseNamespace . 'NotFoundController';
        }

        // Support for nested controllers (e.g., 'api/v1/zone', 'api/internal/zone')
        if (strpos($page, '/') !== false) {
            $parts = explode('/', $page);
            $namespace = '';

            // Process all parts except the last one as namespace components
            for ($i = 0; $i < count($parts) - 1; $i++) {
                // Use proper capitalization for API version segments (v1, v2, etc.)
                if (strtolower($parts[$i]) === 'api' && isset($parts[$i + 1]) && preg_match('/^v\d+$/i', $parts[$i + 1])) {
                    $namespace .= '\\' . ucfirst($parts[$i]) . '\\' . strtoupper($parts[$i + 1]);
                    $i++; // Skip the next part as we've already processed it
                } else {
                    $namespace .= '\\' . ucfirst($parts[$i]);
                }
            }

            // Last part is the class name
            $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $parts[count($parts) - 1])));

            return $baseNamespace . ltrim($namespace, '\\') . '\\' . $className . 'Controller';
        }

        // Standard controller path
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

        // API paths should always use controller authentication mechanisms
        // instead of relying on session-based authentication
        if (str_starts_with($pageName, 'api/')) {
            // Internal API routes should maintain session for logged-in users
            if (str_starts_with($pageName, 'api/internal/')) {
                // Internal API routes use session authentication
                $controller = new $controllerClassName($this->request);
                $controller->run();
            } else {
                // Public API routes (v1, v2, etc.) should not use session
                $originalSession = $_SESSION;
                $_SESSION = [];

                // Pass path parameters to API controllers
                $controller = new $controllerClassName($this->request, $this->pathParameters);
                $controller->run();

                // Restore the session after API controller is done
                $_SESSION = $originalSession;
            }
        } else {
            // Standard controller instantiation for non-API routes
            $controller = new $controllerClassName($this->request);
            $controller->run();
        }
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

    /**
     * Process RESTful API routes and extract path parameters
     *
     * @param string $path The request path
     * @return string The processed route for controller resolution
     */
    private function processRestfulRoute(string $path): string
    {
        $parts = explode('/', $path);

        // Reset path parameters for each request
        $this->pathParameters = [];

        // Handle API versioned routes (api/v1/...)
        if (count($parts) >= 3 && $parts[0] === 'api' && preg_match('/^v\d+$/', $parts[1])) {
            $resource = $parts[2];

            // Handle .htaccess rewritten paths with underscore (e.g., api/v1/zones_records/46/123)
            if (strpos($resource, '_') !== false) {
                // Extract resource and sub-resource from compound name
                $resourceParts = explode('_', $resource, 2);
                $mainResource = $resourceParts[0];
                $subResource = $resourceParts[1];

                // Extract IDs from remaining path segments
                if (isset($parts[3]) && is_numeric($parts[3])) {
                    $this->pathParameters['id'] = (int)$parts[3];
                }
                if (isset($parts[4]) && is_numeric($parts[4])) {
                    $this->pathParameters['sub_id'] = (int)$parts[4];
                }

                // Return path to sub-resource controller
                return "api/{$parts[1]}/{$mainResource}_{$subResource}";
            }

            // Extract resource ID if present (e.g., api/v1/zones/123)
            if (isset($parts[3]) && is_numeric($parts[3])) {
                $this->pathParameters['id'] = (int)$parts[3];

                // Check for sub-resources (e.g., api/v1/zones/123/records)
                if (isset($parts[4])) {
                    $subResource = $parts[4];

                    // Extract sub-resource ID if present (e.g., api/v1/zones/123/records/456)
                    if (isset($parts[5]) && is_numeric($parts[5])) {
                        $this->pathParameters['sub_id'] = (int)$parts[5];
                    }

                    // Return path to sub-resource controller
                    return "api/{$parts[1]}/{$resource}_{$subResource}";
                }

                // Return path to main resource controller
                return "api/{$parts[1]}/{$resource}";
            }

            // Handle special endpoints (e.g., api/v1/user/verify)
            if (isset($parts[3]) && !is_numeric($parts[3])) {
                $action = $parts[3];

                // For special endpoints, create a specific controller path
                return "api/{$parts[1]}/{$resource}_{$action}";
            }

            // Return base resource path (e.g., api/v1/zones)
            return "api/{$parts[1]}/{$resource}";
        }

        // Fallback to original path for non-standard API routes
        return $path;
    }

    /**
     * Get extracted path parameters from RESTful routes
     *
     * @return array The path parameters
     */
    public function getPathParameters(): array
    {
        return $this->pathParameters;
    }

    /**
     * Check if the requested route was found
     *
     * @return bool Whether the route was found
     */
    public function isRouteFound(): bool
    {
        return $this->routeFound;
    }
}
