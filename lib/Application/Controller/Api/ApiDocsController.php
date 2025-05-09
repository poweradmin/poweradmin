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

/**
 * API Documentation controller
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api;

use Poweradmin\BaseController;
use Symfony\Component\HttpFoundation\Response;

/**
 * @OA\Info(
 *     title="Poweradmin API",
 *     version="1.0.0",
 *     description="API for Poweradmin DNS Management",
 *     @OA\Contact(
 *         email="admin@poweradmin.org",
 *         name="Poweradmin Team"
 *     ),
 *     @OA\License(
 *         name="GPL-3.0",
 *         url="https://opensource.org/licenses/GPL-3.0"
 *     )
 * )
 * @OA\Server(
 *     url="/api",
 *     description="API Server"
 * )
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="API Key"
 * )
 * @OA\SecurityScheme(
 *     securityScheme="apiKeyHeader",
 *     type="apiKey",
 *     name="X-API-Key",
 *     in="header"
 * )
 */
class ApiDocsController extends BaseController
{
    // Inherits config from parent

    /**
     * Constructor for ApiDocsController
     *
     * @param array $request The request data
     */
    public function __construct(array $request)
    {
        parent::__construct($request);
    }

    /**
     * Run the controller
     */
    public function run(): void
    {
        // Only show API docs in development mode or when explicitly enabled
        if (!$this->isApiDocsEnabled()) {
            $this->show404Page();
            return;
        }

        // Create Symfony request from globals
        $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
        $action = $request->query->get('action', 'ui');

        switch ($action) {
            case 'json':
                $this->showOpenApiJson();
                break;
            case 'ui':
            default:
                $this->showSwaggerUi();
                break;
        }
    }

    /**
     * Show Swagger UI
     */
    private function showSwaggerUi(): void
    {
        $response = new Response();
        $response->headers->set('Content-Type', 'text/html');

        // Get the base URL for API docs JSON
        $baseUrl = $this->getBaseUrl();
        $jsonUrl = $baseUrl . '?action=json';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Poweradmin API Documentation</title>
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@5.10.5/swagger-ui.css">
    <style>
        html {
            box-sizing: border-box;
            overflow: -moz-scrollbars-vertical;
            overflow-y: scroll;
        }
        
        *,
        *:before,
        *:after {
            box-sizing: inherit;
        }
        
        body {
            margin: 0;
            background: #fafafa;
        }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    
    <script src="https://unpkg.com/swagger-ui-dist@5.10.5/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5.10.5/swagger-ui-standalone-preset.js"></script>
    <script>
        window.onload = function() {
            window.ui = SwaggerUIBundle({
                url: "{$jsonUrl}",
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: "StandaloneLayout"
            });
        };
    </script>
</body>
</html>
HTML;

        $response->setContent($html);
        $response->send();
        exit;
    }

    /**
     * Generate and show OpenAPI JSON
     */
    private function showOpenApiJson(): void
    {
        // Only load Swagger classes in development environment
        if (!class_exists('\OpenApi\Generator')) {
            $response = new Response('OpenAPI Generator not available', Response::HTTP_SERVICE_UNAVAILABLE);
            $response->send();
            exit;
        }

        // Generate OpenAPI spec
        $openapi = \OpenApi\Generator::scan([
            __DIR__ . '/v1',
            __DIR__,
        ]);

        $response = new Response();
        $response->headers->set('Content-Type', 'application/json');
        $response->setContent($openapi->toJson());
        $response->send();
        exit;
    }

    /**
     * Check if API docs should be enabled
     *
     * @return bool True if API docs should be enabled
     */
    private function isApiDocsEnabled(): bool
    {
        // Check if API docs are explicitly enabled in config
        return (bool) $this->config->get('api', 'docs_enabled');
    }

    /**
     * Show 404 page
     */
    private function show404Page(): void
    {
        $response = new Response('API documentation not available', Response::HTTP_NOT_FOUND);
        $response->send();
        exit;
    }

    /**
     * Get the base URL for the API docs
     *
     * @return string The base URL
     */
    private function getBaseUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        // Remove query string from URI
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        return $protocol . $host . $uri;
    }
}
