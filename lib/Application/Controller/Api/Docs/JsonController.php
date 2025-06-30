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
 * API Documentation JSON Controller
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api\Docs;

use Exception;
use OpenApi\Generator;
use Poweradmin\BaseController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class JsonController
 *
 * This controller serves the OpenAPI JSON specification generated from annotations.
 */
class JsonController extends BaseController
{
    /**
     * Constructor for JsonController
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
        try {
            // Generate OpenAPI specification from annotations
            // Current path: /lib/Application/Controller/Api/Docs/JsonController.php
            // Target path: /lib/Application/Controller/Api/V1/
            $scanPath = dirname(dirname(__FILE__)) . '/V1';

            if (!is_dir($scanPath)) {
                throw new Exception("Scan path does not exist: " . $scanPath);
            }

            $openapi = Generator::scan([$scanPath], [
                'validate' => false,  // Disable validation to prevent issues
            ]);

            // Get JSON content
            $jsonContent = $openapi->toJson();

            // Verify we have actual paths
            $decoded = json_decode($jsonContent, true);
            if (empty($decoded['paths'])) {
                throw new Exception('No paths found in generated OpenAPI spec');
            }

            // Set response headers
            $response = new Response();
            $response->headers->set('Content-Type', 'application/json');
            $response->setContent($jsonContent);
            $response->send();
        } catch (Exception $e) {
            // Log the actual error for debugging
            error_log("OpenAPI generation failed: " . $e->getMessage());

            // Return minimal OpenAPI spec if generation fails
            $fallbackSpec = [
                'openapi' => '3.0.0',
                'info' => [
                    'title' => 'Poweradmin API',
                    'version' => '1.0.0',
                    'description' => 'RESTful API for Poweradmin DNS Management - Generation Failed',
                    'contact' => [
                        'name' => 'API Documentation Error',
                        'url' => 'Check server logs for details'
                    ]
                ],
                'paths' => [],
                'components' => [
                    'securitySchemes' => [
                        'bearerAuth' => [
                            'type' => 'http',
                            'scheme' => 'bearer',
                            'bearerFormat' => 'API Key'
                        ],
                        'apiKeyHeader' => [
                            'type' => 'apiKey',
                            'name' => 'X-API-Key',
                            'in' => 'header'
                        ]
                    ]
                ]
            ];

            $response = new Response();
            $response->headers->set('Content-Type', 'application/json');
            $response->setContent(json_encode($fallbackSpec, JSON_PRETTY_PRINT));
            $response->send();
        }

        exit;
    }
}
