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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Module\ModuleRegistry;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;

/**
 * Class SymfonyRouter
 *
 * Symfony Router implementation for Poweradmin with module support.
 * Uses modern routing patterns with clean URLs and proper REST endpoints.
 * Loads routes from YAML configuration and enabled modules.
 *
 * @package Poweradmin\Application\Routing
 */
class SymfonyRouter
{
    private UrlMatcher $matcher;
    private UrlGenerator $urlGenerator;
    private Request $request;
    private array $routeParameters = [];
    private ?string $matchedRoute = null;
    private bool $routeFound = true;

    public function __construct()
    {
        $this->request = Request::createFromGlobals();
        $this->initializeRouter();
    }

    /**
     * Initialize the router with YAML routes and module routes.
     * Handles base_url_prefix for subfolder deployments.
     */
    private function initializeRouter(): void
    {
        $configDir = __DIR__ . '/../Config';
        $fileLocator = new FileLocator([$configDir]);
        $loader = new YamlFileLoader($fileLocator);

        // Load YAML routes into a RouteCollection
        $routes = $loader->load('routes.yaml');

        // Load module routes from enabled modules
        $config = ConfigurationManager::getInstance();
        $registry = new ModuleRegistry($config);
        $registry->loadModules();

        foreach ($registry->getRoutes() as $routeDef) {
            $defaults = ['_controller' => $routeDef['controller']];
            $requirements = $routeDef['requirements'] ?? [];

            $route = new Route($routeDef['path'], $defaults, $requirements);

            if (isset($routeDef['methods'])) {
                $route->setMethods($routeDef['methods']);
            }

            $routes->add($routeDef['name'], $route);
        }

        // Set up request context with subfolder support
        $context = new RequestContext();
        $context->fromRequest($this->request);

        $baseUrlPrefix = $config->get('interface', 'base_url_prefix', '');
        if (!empty($baseUrlPrefix)) {
            $context->setBaseUrl($baseUrlPrefix);
        }

        $this->matcher = new UrlMatcher($routes, $context);
        $this->urlGenerator = new UrlGenerator($routes, $context);
    }

    /**
     * Match the current request to a route and return controller information.
     *
     * @return array Contains controller class, method, and parameters
     * @throws Exception If no route matches or controller not found
     */
    public function match(): array
    {
        try {
            $pathInfo = $this->request->getPathInfo();
            $parameters = $this->matcher->match($pathInfo);

            $this->routeParameters = $parameters;
            $this->matchedRoute = $parameters['_route'] ?? null;
            $this->routeFound = true;

            $controller = $parameters['_controller'];

            // Parse controller string (e.g., "App\Controller\HomeController::index")
            if (strpos($controller, '::') !== false) {
                [$controllerClass, $method] = explode('::', $controller);
            } else {
                $controllerClass = $controller;
                $method = $this->getMethodFromHttpVerb();
            }

            // Remove route-specific parameters to get clean parameters
            $cleanParameters = array_filter($parameters, function ($key) {
                return !str_starts_with($key, '_');
            }, ARRAY_FILTER_USE_KEY);

            return [
                'controller' => $controllerClass,
                'method' => $method,
                'parameters' => $cleanParameters,
                'route' => $this->matchedRoute
            ];
        } catch (ResourceNotFoundException $e) {
            $this->routeFound = false;
            return [
                'controller' => '\Poweradmin\Application\Controller\NotFoundController',
                'method' => 'run',
                'parameters' => [],
                'route' => '404'
            ];
        } catch (MethodNotAllowedException $e) {
            $this->routeFound = false;
            throw new Exception('Method not allowed', 405);
        }
    }

    /**
     * Process the matched route by instantiating and running the controller.
     */
    public function process(): void
    {
        $routeInfo = $this->match();

        $controllerClass = $routeInfo['controller'];
        $method = $routeInfo['method'];
        $parameters = $routeInfo['parameters'];

        if (!class_exists($controllerClass)) {
            throw new Exception("Controller class {$controllerClass} not found");
        }

        // Create controller instance
        if ($this->isApiRoute()) {
            // API controllers get clean parameter injection
            // Pass $_REQUEST as first parameter and route parameters as second
            $controller = new $controllerClass($_REQUEST, $parameters);
        } else {
            // Web controllers maintain current request structure for compatibility
            // but merge route parameters into the request data
            $requestData = array_merge($_REQUEST, $parameters);
            // Add the route name as 'page' for template navigation
            $requestData['page'] = $routeInfo['route'];
            $controller = new $controllerClass($requestData);
        }

        // Check if method exists
        if (!method_exists($controller, $method)) {
            throw new Exception("Method {$method} not found in {$controllerClass}");
        }

        // Execute controller method
        $controller->$method();
    }

    /**
     * Determine HTTP method to controller method mapping.
     */
    private function getMethodFromHttpVerb(): string
    {
        return match ($this->request->getMethod()) {
            'GET' => 'index',
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'run'
        };
    }

    /**
     * Check if current route is an API route.
     */
    private function isApiRoute(): bool
    {
        return str_starts_with($this->request->getPathInfo(), '/api/');
    }

    /**
     * Generate URL for a named route.
     */
    public function generateUrl(string $routeName, array $parameters = []): string
    {
        return $this->urlGenerator->generate($routeName, $parameters);
    }

    /**
     * Get route parameters from the matched route.
     */
    public function getRouteParameters(): array
    {
        return $this->routeParameters;
    }

    /**
     * Get the matched route name.
     */
    public function getMatchedRoute(): ?string
    {
        return $this->matchedRoute;
    }

    /**
     * Check if route was found.
     */
    public function isRouteFound(): bool
    {
        return $this->routeFound;
    }

    /**
     * Get the current request object.
     */
    public function getRequest(): Request
    {
        return $this->request;
    }
}
