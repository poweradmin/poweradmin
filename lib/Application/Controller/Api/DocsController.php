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
 * API Documentation Controller (Swagger UI)
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
 * Class DocsController
 *
 * This controller serves the Swagger UI for API documentation.
 */
class DocsController extends BaseController
{
    /**
     * Constructor for DocsController
     *
     * @param array $request The request data
     */
    public function __construct(array $request)
    {
        // Disable authentication for docs endpoint
        parent::__construct($request, false);
    }

    /**
     * Run the controller
     */
    public function run(): void
    {
        // Check if API documentation is enabled
        if (!$this->config->get('api', 'docs_enabled')) {
            $response = new Response();
            $response->setStatusCode(404);
            $response->headers->set('Content-Type', 'text/html');
            $response->setContent('<html><body><h1>404 Not Found</h1><p>API documentation is disabled.</p></body></html>');
            $response->send();
            exit;
        }

        // Generate Swagger UI HTML
        $html = $this->generateSwaggerUI();

        // Set response
        $response = new Response();
        $response->headers->set('Content-Type', 'text/html');
        $response->setContent($html);
        $response->send();

        exit;
    }

    /**
     * Generate Swagger UI HTML
     *
     * @return string The HTML content
     */
    private function generateSwaggerUI(): string
    {
        // Get the base URL for API docs
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $this->getValidatedHost();
        $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
        $baseUrl = $protocol . '://' . $host . $baseUrlPrefix;
        $apiJsonUrl = $baseUrl . '/api/docs/json';

        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Poweradmin API Documentation</title>
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@5.10.5/swagger-ui.css" />
    <style>
        html {
            box-sizing: border-box;
            overflow: -moz-scrollbars-vertical;
            overflow-y: scroll;
        }
        *, *:before, *:after {
            box-sizing: inherit;
        }
        body {
            margin:0;
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
            // Build a system
            const ui = SwaggerUIBundle({
                url: \'' . $apiJsonUrl . '\',
                dom_id: \'#swagger-ui\',
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
                tryItOutEnabled: true,
                requestInterceptor: function(request) {
                    // Add API key header if available in localStorage
                    const apiKey = localStorage.getItem(\'poweradmin_api_key\');
                    if (apiKey) {
                        request.headers[\'X-API-Key\'] = apiKey;
                    }
                    return request;
                }
            });

            // Add custom CSS
            const style = document.createElement(\'style\');
            style.textContent = `
                .topbar { display: none; }
                .info .title { color: #3b4151; }
                .swagger-ui .info .title:after {
                    content: " - Poweradmin DNS Management";
                    font-size: 0.6em;
                    color: #666;
                }
            `;
            document.head.appendChild(style);

            window.ui = ui;
        };
    </script>
</body>
</html>';
    }

    /**
     * Get validated and sanitized host from HTTP_HOST header
     *
     * @return string Safe hostname or localhost fallback
     */
    private function getValidatedHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // Remove port number if present for validation
        $hostOnly = preg_replace('/:\d+$/', '', $host);

        // Validate hostname format
        if (!$this->isValidHostname($hostOnly)) {
            return 'localhost';
        }

        // Return the original host (including port) if validation passed
        return $host;
    }

    /**
     * Validate hostname format
     *
     * @param string $hostname The hostname to validate
     * @return bool True if hostname is valid
     */
    private function isValidHostname(string $hostname): bool
    {
        // Check for valid hostname format
        if (!filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            // Fallback validation for IP addresses
            if (!filter_var($hostname, FILTER_VALIDATE_IP)) {
                return false;
            }
        }

        // Additional security checks
        if (strlen($hostname) > 253) {
            return false;
        }

        // Prevent obviously malicious patterns
        if (
            strpos($hostname, '\'') !== false ||
            strpos($hostname, '"') !== false ||
            strpos($hostname, '<') !== false ||
            strpos($hostname, '>') !== false ||
            strpos($hostname, ';') !== false
        ) {
            return false;
        }

        return true;
    }
}
