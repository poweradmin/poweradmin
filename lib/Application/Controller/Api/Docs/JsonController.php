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

use OpenApi\Generator;
use OpenApi\Attributes as OA;
use Poweradmin\Application\Controller\Api\DocsController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class JsonController
 *
 * This controller serves the OpenAPI JSON specification for the Swagger UI.
 */
class JsonController extends DocsController
{
    /**
     * Constructor for JsonController
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

        try {
            // Set error handling
            $errorLevel = error_reporting();
            error_reporting(E_ERROR); // Suppress warnings

            // Generate JSON
            $json = $this->generateOpenApiJson();

            // Restore error reporting
            error_reporting($errorLevel);

            // Send response
            $response = new Response();
            $response->headers->set('Content-Type', 'application/json');
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-Key');
            $response->headers->set('Access-Control-Max-Age', '3600');
            $response->setContent($json);
            $response->send();
        } catch (\Exception $e) {
            // Log the error
            $errorMessage = 'Error generating OpenAPI documentation: ' . $e->getMessage();
            error_log($errorMessage);

            // Write detailed error information to a log file
            $logFile = __DIR__ . '/../../../../openapi-error.log';
            file_put_contents($logFile, $errorMessage . "\n\n" . $e->getTraceAsString());

            // Return error response
            $response = new Response();
            $response->setContent(json_encode([
                'error' => true,
                'message' => 'Error generating API documentation. See server logs for details.'
            ]));
            $response->headers->set('Content-Type', 'application/json');
            $response->setStatusCode(500);
            $response->send();
        }

        exit;
    }

    /**
     * Generate OpenAPI JSON specification
     *
     * @return string OpenAPI JSON specification
     */
    private function generateOpenApiJson(): string
    {
        // Create manual JSON OpenAPI spec
        $spec = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Poweradmin API',
                'version' => '1.0.0',
                'description' => 'API for Poweradmin DNS Management',
                'contact' => [
                    'email' => 'edmondas@poweradmin.org',
                    'name' => 'Poweradmin Development Team'
                ],
                'license' => [
                    'name' => 'GPL-3.0',
                    'url' => 'https://opensource.org/licenses/GPL-3.0'
                ]
            ],
            'servers' => [
                [
                    'url' => '/api',
                    'description' => 'API Server'
                ]
            ],
            'paths' => [
                '/v1/auth/test' => [
                    'get' => [
                        'summary' => 'Test API authentication credentials',
                        'description' => 'Verifies the current authentication credentials and returns user information',
                        'operationId' => 'v1AuthTest',
                        'tags' => ['users'],
                        'security' => [
                            ['bearerAuth' => []],
                            ['apiKeyHeader' => []]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Authentication successful',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'authenticated' => [
                                                    'type' => 'boolean',
                                                    'example' => true
                                                ],
                                                'user_id' => [
                                                    'type' => 'integer',
                                                    'example' => 1
                                                ],
                                                'username' => [
                                                    'type' => 'string',
                                                    'example' => 'admin'
                                                ],
                                                'auth_method' => [
                                                    'type' => 'string',
                                                    'example' => 'api_key'
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ],
                            '401' => [
                                'description' => 'Authentication failed',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'error' => [
                                                    'type' => 'boolean',
                                                    'example' => true
                                                ],
                                                'message' => [
                                                    'type' => 'string',
                                                    'example' => 'Authentication required'
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                '/v1/user/verify' => [
                    'get' => [
                        'summary' => 'Verify a user and API key',
                        'operationId' => 'v1UserVerify',
                        'tags' => ['users'],
                        'security' => [
                            ['bearerAuth' => []],
                            ['apiKeyHeader' => []]
                        ],
                        'parameters' => [
                            [
                                'name' => 'action',
                                'in' => 'query',
                                'required' => true,
                                'description' => 'Action parameter (must be \'verify\')',
                                'schema' => [
                                    'type' => 'string',
                                    'default' => 'verify',
                                    'enum' => ['verify']
                                ]
                            ]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'User and API key verification result'
                            ],
                            '401' => [
                                'description' => 'Unauthorized'
                            ]
                        ]
                    ]
                ],
                '/v1/zone/list' => [
                    'get' => [
                        'summary' => 'List all accessible zones',
                        'operationId' => 'v1ZoneList',
                        'tags' => ['zones'],
                        'security' => [
                            ['bearerAuth' => []],
                            ['apiKeyHeader' => []]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'List of zones'
                            ],
                            '401' => [
                                'description' => 'Unauthorized'
                            ]
                        ]
                    ]
                ],
                '/v1/zone/create' => [
                    'post' => [
                        'summary' => 'Create a new zone',
                        'operationId' => 'v1ZoneCreate',
                        'tags' => ['zones'],
                        'security' => [
                            ['bearerAuth' => []],
                            ['apiKeyHeader' => []]
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Zone created successfully'
                            ],
                            '400' => [
                                'description' => 'Bad request'
                            ],
                            '401' => [
                                'description' => 'Unauthorized'
                            ]
                        ]
                    ]
                ],
                '/v1/zone/record/add' => [
                    'post' => [
                        'summary' => 'Add a new DNS record to a zone',
                        'operationId' => 'v1ZoneRecordAdd',
                        'tags' => ['zones'],
                        'security' => [
                            ['bearerAuth' => []],
                            ['apiKeyHeader' => []]
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Record created successfully'
                            ],
                            '400' => [
                                'description' => 'Bad request'
                            ],
                            '401' => [
                                'description' => 'Unauthorized'
                            ],
                            '404' => [
                                'description' => 'Zone not found'
                            ]
                        ]
                    ]
                ],
                '/v1/zone/delete' => [
                    'delete' => [
                        'summary' => 'Delete a zone',
                        'operationId' => 'v1ZoneDelete',
                        'tags' => ['zones'],
                        'security' => [
                            ['bearerAuth' => []],
                            ['apiKeyHeader' => []]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Zone deleted successfully'
                            ],
                            '400' => [
                                'description' => 'Bad request'
                            ],
                            '401' => [
                                'description' => 'Unauthorized'
                            ],
                            '404' => [
                                'description' => 'Zone not found'
                            ]
                        ]
                    ]
                ]
            ],
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
            ],
            'tags' => [
                ['name' => 'users', 'description' => 'User management and authentication'],
                ['name' => 'zones', 'description' => 'Zone and DNS record management']
            ]
        ];

        // Convert to JSON with pretty formatting
        $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Ensure we always return a string
        return $json !== false ? $json : '{}';
    }
}
