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

use OpenApi\Generator;
use OpenApi\Attributes as OA;
use Poweradmin\BaseController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[OA\OpenApi(openapi: '3.0.0')]
#[OA\Info(
    title: "Poweradmin API",
    version: "1.0.0",
    description: "API for Poweradmin DNS Management",
    contact: new OA\Contact(
        email: "edmondas@poweradmin.org",
        name: "Poweradmin Development Team"
    ),
    license: new OA\License(
        name: "GPL-3.0",
        url: "https://opensource.org/licenses/GPL-3.0"
    )
)]
#[OA\Server(
    url: "/",
    description: "API Server"
)]
#[OA\SecurityScheme(
    securityScheme: "bearerAuth",
    type: "http",
    scheme: "bearer",
    bearerFormat: "API Key"
)]
#[OA\SecurityScheme(
    securityScheme: "apiKeyHeader",
    type: "apiKey",
    name: "X-API-Key",
    in: "header"
)]
class DocsController extends BaseController
{
    // Inherits config from parent

    /**
     * Constructor for DocsController
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
        $request = Request::createFromGlobals();
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

        // Set security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' https://unpkg.com 'unsafe-inline'; style-src 'self' https://unpkg.com 'unsafe-inline'; img-src 'self' data: https://unpkg.com; connect-src 'self'");

        // Get the base URL for API docs JSON
        $baseUrl = $this->getBaseUrl();
        $jsonUrl = $baseUrl . '?action=json';

        // Get API details from configuration
        $apiTitle = "Poweradmin API";
        $apiVersion = "1.0.0";
        $appName = "Poweradmin";

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="$appName API Documentation">
    <title>$apiTitle Documentation</title>
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@5.11.2/swagger-ui.css">
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
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                         Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }

        .swagger-ui .topbar {
            background-color: #23282d;
        }

        .swagger-ui .info {
            margin: 20px 0;
        }

        .swagger-ui .info .title {
            font-size: 24px;
        }

        /* Custom header */
        .header {
            background-color: #23282d;
            color: white;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            margin: 0;
            font-size: 1.5em;
            display: flex;
            align-items: center;
        }

        .header .version {
            background-color: #0073aa;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            margin-left: 10px;
            font-size: 0.8em;
        }

        .header a {
            color: white;
            text-decoration: none;
            font-size: 0.9em;
            margin-left: 15px;
        }

        .header a:hover {
            text-decoration: underline;
        }

        .nav-links {
            display: flex;
        }

        /* Dark theme toggle */
        .theme-toggle {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 0.9em;
            display: flex;
            align-items: center;
        }

        .theme-toggle svg {
            margin-right: 5px;
        }

        /* Documentation help */
        .docs-help {
            margin: 10px 20px;
            padding: 10px 15px;
            background-color: #f0f0f0;
            border-left: 4px solid #0073aa;
            border-radius: 3px;
        }

        .docs-help h3 {
            margin-top: 0;
            font-size: 1em;
        }

        .docs-help ul {
            margin-bottom: 0;
        }

        .docs-help li {
            margin-bottom: 5px;
        }

        /* Dark theme support */
        body.dark-theme {
            background: #1e1e1e;
            color: #e0e0e0;
        }

        body.dark-theme .docs-help {
            background-color: #2c2c2c;
            border-left-color: #3a9bcf;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>
            $appName
            <span class="version">v$apiVersion</span>
        </h1>
        <div class="nav-links">
            <button class="theme-toggle" id="theme-toggle" aria-label="Toggle dark theme">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM8 0a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 0zm0 13a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 13zm8-5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2a.5.5 0 0 1 .5.5zM3 8a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2A.5.5 0 0 1 3 8zm10.657-5.657a.5.5 0 0 1 0 .707l-1.414 1.415a.5.5 0 1 1-.707-.708l1.414-1.414a.5.5 0 0 1 .707 0zm-9.193 9.193a.5.5 0 0 1 0 .707L3.05 13.657a.5.5 0 0 1-.707-.707l1.414-1.414a.5.5 0 0 1 .707 0zm9.193 2.121a.5.5 0 0 1-.707 0l-1.414-1.414a.5.5 0 0 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707zM4.464 4.465a.5.5 0 0 1-.707 0L2.343 3.05a.5.5 0 1 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .708z"/>
                </svg>
                <span>Toggle theme</span>
            </button>
            <a href="/" target="_blank">Home</a>
        </div>
    </div>

    <div class="docs-help">
        <h3>Using the API Documentation</h3>
        <ul>
            <li>Endpoints are grouped by tags and can be expanded by clicking on them.</li>
            <li>Use the "Try it out" button to test API calls.</li>
            <li>Authentication is required for most endpoints using an API key.</li>
            <li>For more information, check the <a href="https://docs.poweradmin.org" target="_blank">official documentation</a>.</li>
        </ul>
    </div>

    <div id="swagger-ui"></div>

    <script src="https://unpkg.com/swagger-ui-dist@5.11.2/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5.11.2/swagger-ui-standalone-preset.js"></script>
    <script>
        window.onload = function() {
            // Initialize Swagger UI
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
                layout: "StandaloneLayout",
                validatorUrl: null,
                tagsSorter: "alpha",
                operationsSorter: "alpha",
                docExpansion: "none",
                filter: true,
                syntaxHighlight: {
                    activated: true,
                    theme: "agate"
                }
            });

            // Add dark theme support
            const themeToggle = document.getElementById('theme-toggle');

            // Check user preference for dark/light theme
            const prefersDarkTheme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

            // Apply dark theme if user prefers it
            if (prefersDarkTheme) {
                document.body.classList.add('dark-theme');
            }

            // Theme toggle functionality
            themeToggle.addEventListener('click', function() {
                document.body.classList.toggle('dark-theme');
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

        try {
            // Define the OpenAPI tags first to ensure proper organization
            $tags = [
                new OA\Tag(name: 'zones', description: 'Zone and DNS record management'),
                new OA\Tag(name: 'users', description: 'User authentication and verification')
            ];

            // Configure OpenAPI generator with advanced options
            $options = [
                'validate' => true,
                'security' => [],  // Global security applied to all operations
                'tags' => $tags
            ];

            // Generate OpenAPI spec with verbose error handling
            $openapi = Generator::scan([
                __DIR__ . '/v1',  // API controllers
                __DIR__,          // Base API docs
            ], $options);

            // Add CORS headers to allow external tools to access the documentation
            $json = $openapi->toJson();

            // Validate JSON to catch syntax errors
            json_decode($json);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('JSON error: ' . json_last_error_msg());
            }

            $response = new Response();
            $response->headers->set('Content-Type', 'application/json');

            // Add CORS headers to allow external tools to access the documentation
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-Key');
            $response->headers->set('Access-Control-Max-Age', '3600');

            $response->setContent($json);
            $response->send();
        } catch (\Exception $e) {
            $response = new Response(
                'Error generating OpenAPI documentation: ' . $e->getMessage() .
                "\nTrace: " . $e->getTraceAsString(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
            $response->headers->set('Content-Type', 'text/plain');
            $response->send();
        }
        exit;
    }

    /**
     * Check if API docs should be enabled
     *
     * @return bool True if API docs should be enabled
     */
    private function isApiDocsEnabled(): bool
    {
        // Check if API is enabled first
        $apiEnabled = (bool) $this->config->get('api', 'enabled', false);
        if (!$apiEnabled) {
            return false;
        }

        // Check if API docs are explicitly enabled in config
        $docsEnabled = (bool) $this->config->get('api', 'docs_enabled', false);

        // Check if we're in development mode
        $devMode = (bool) $this->config->get('general', 'development_mode', false);

        // Allow docs in development mode or when explicitly enabled
        return $docsEnabled || $devMode;
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
