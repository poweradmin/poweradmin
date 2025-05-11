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
        // The authentication logic is inherited from the parent DocsController
        // which is now disabled (passing false to BaseController)
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
            // Quick check for OpenAPI classes before proceeding
            if (!class_exists('OpenApi\\Generator')) {
                error_log('OpenApi\\Generator class not found - no need to log a full stack trace for this expected condition');

                // Send a valid JSON response using the fallback schema
                $response = new Response();
                $response->headers->set('Content-Type', 'application/json');
                $response->setContent($this->createFallbackOpenApiJson());
                $response->send();
                exit;
            }

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
        } catch (\Error $e) {
            // Handle PHP Errors (like class not found)
            error_log('PHP Error generating OpenAPI documentation: ' . $e->getMessage());

            // Return fallback schema rather than an error
            $response = new Response();
            $response->headers->set('Content-Type', 'application/json');
            $response->setContent($this->createFallbackOpenApiJson());
            $response->send();
        } catch (\Exception $e) {
            // Log the error
            $errorMessage = 'Exception generating OpenAPI documentation: ' . $e->getMessage();
            error_log($errorMessage);

            // Write detailed error information to a log file
            $logFile = __DIR__ . '/../../../../openapi-error.log';
            file_put_contents($logFile, $errorMessage . "\n\n" . $e->getTraceAsString());

            // Return API docs with fallback schema instead of error message
            $response = new Response();
            $response->headers->set('Content-Type', 'application/json');
            $response->setContent($this->createFallbackOpenApiJson());
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
        // Quick check if OpenAPI Generator class exists
        if (!class_exists('OpenApi\\Generator')) {
            error_log('OpenApi\\Generator class not found - using fallback schema');
            return $this->createFallbackOpenApiJson();
        }

        try {
            // Define OpenAPI tags for organization
            $tags = [
                ['name' => 'zones', 'description' => 'Zone and DNS record management'],
                ['name' => 'users', 'description' => 'User authentication and verification']
            ];

            // Configure OpenAPI generator with options
            $options = [
                'validate' => false,  // Set to false to prevent validation errors
                'tags' => $tags,
                'openapi' => '3.0.0'  // Explicitly specify OpenAPI version
            ];

            // Get the project root directory (two levels up from this file)
            $projectRoot = dirname(dirname(dirname(dirname(dirname(__FILE__)))));

            // Log the scan directory for debugging
            $scanDirectory = $projectRoot . '/lib/Application/Controller/Api';
            error_log('OpenAPI scan directory: ' . $scanDirectory);
            error_log('Directory exists: ' . (is_dir($scanDirectory) ? 'Yes' : 'No'));

            // Store files to be scanned for logging
            $filesToScan = [];
            $this->findPhpFilesRecursively($scanDirectory, $filesToScan);
            error_log('Number of PHP files found: ' . count($filesToScan));

            // Now try scanning the codebase for OpenAPI annotations/attributes
            error_log('Starting OpenAPI scanning of files...');

            try {
                // Additional check for required classes before scanning
                if (!class_exists('OpenApi\\Annotations\\OpenApi')) {
                    error_log('OpenApi\\Annotations\\OpenApi class not found - using fallback schema');
                    throw new \Exception('Required OpenAPI annotation classes not found');
                }

                // Try to scan the codebase
                $openapi = Generator::scan([
                    $scanDirectory,  // All API controllers directory
                ], $options);

                // Try to convert to JSON
                $json = $openapi->toJson();

                // Parse the JSON to see if it contains the expected structures
                $decodedJson = json_decode($json, true);

                if (json_last_error() !== JSON_ERROR_NONE || empty($decodedJson['paths'])) {
                    error_log('Generated JSON is invalid or empty, falling back to manual schema');
                    return $this->createFallbackOpenApiJson();
                }

                // If we get this far, the scanning worked
                error_log('Scanning worked and produced valid JSON with paths');
                return $json;
            } catch (\Exception $e) {
                // If scanning or JSON conversion fails, log and use fallback
                error_log('Scanning or JSON conversion failed: ' . $e->getMessage());
                return $this->createFallbackOpenApiJson();
            }
        } catch (\Exception $e) {
            // Log detailed exception information
            error_log('OpenAPI generation error: ' . $e->getMessage());
            error_log('Exception trace: ' . $e->getTraceAsString());

            // Return manual schema as fallback
            return $this->createFallbackOpenApiJson();
        }
    }

    /**
     * Create a fallback OpenAPI JSON with zone endpoints and parameters manually defined
     *
     * @return string The fallback OpenAPI JSON
     */
    private function createFallbackOpenApiJson(): string
    {
        // Create a simplified hardcoded OpenAPI spec with minimal endpoints
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
                '/v1/user/get' => [
                    'get' => [
                        'summary' => 'Get user information by ID',
                        'description' => 'Retrieves user information and permissions for a specific user ID',
                        'operationId' => 'v1UserGet',
                        'tags' => ['users'],
                        'security' => [['bearerAuth' => []], ['apiKeyHeader' => []]],
                        'parameters' => [
                            [
                                'name' => 'user_id',
                                'in' => 'query',
                                'description' => 'User ID to retrieve information for',
                                'required' => true,
                                'schema' => ['type' => 'integer']
                            ]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'User information retrieved successfully'
                            ],
                            '401' => [
                                'description' => 'Unauthorized'
                            ],
                            '404' => [
                                'description' => 'User not found'
                            ]
                        ]
                    ]
                ],
                '/v1/zone/list' => [
                    'get' => [
                        'summary' => 'List all accessible zones',
                        'operationId' => 'v1ZoneList',
                        'tags' => ['zones'],
                        'security' => [['bearerAuth' => []], ['apiKeyHeader' => []]],
                        'parameters' => [
                            [
                                'name' => 'pagenum',
                                'in' => 'query',
                                'description' => 'Page number for pagination',
                                'schema' => ['type' => 'integer', 'default' => 1]
                            ],
                            [
                                'name' => 'limit',
                                'in' => 'query',
                                'description' => 'Number of results per page',
                                'schema' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100]
                            ]
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
                '/v1/zone/get/{id}' => [
                    'get' => [
                        'summary' => 'Get a specific zone by ID or name',
                        'operationId' => 'v1ZoneGet',
                        'tags' => ['zones'],
                        'security' => [['bearerAuth' => []], ['apiKeyHeader' => []]],
                        'parameters' => [
                            [
                                'name' => 'id',
                                'in' => 'path',
                                'description' => 'Zone ID or "name" if querying by name',
                                'required' => true,
                                'schema' => ['type' => 'string']
                            ],
                            [
                                'name' => 'by',
                                'in' => 'query',
                                'description' => 'Query by "id" (default) or "name"',
                                'schema' => ['type' => 'string', 'enum' => ['id', 'name'], 'default' => 'id']
                            ]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Zone details'
                            ],
                            '400' => [
                                'description' => 'Missing required parameters'
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
                '/v1/zone/create' => [
                    'post' => [
                        'summary' => 'Create a new zone',
                        'operationId' => 'v1ZoneCreate',
                        'tags' => ['zones'],
                        'security' => [['bearerAuth' => []], ['apiKeyHeader' => []]],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'name' => [
                                                'type' => 'string',
                                                'description' => 'Zone name',
                                                'example' => 'example.com'
                                            ],
                                            'type' => [
                                                'type' => 'string',
                                                'description' => 'Zone type',
                                                'example' => 'MASTER'
                                            ],
                                            'owner' => [
                                                'type' => 'integer',
                                                'description' => 'Zone owner (optional)',
                                                'example' => 1
                                            ],
                                            'dnssec' => [
                                                'type' => 'boolean',
                                                'description' => 'Enable DNSSEC (optional)',
                                                'example' => true
                                            ]
                                        ],
                                        'required' => ['name', 'type']
                                    ]
                                ]
                            ]
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
                '/v1/zone/delete/{id}' => [
                    'delete' => [
                        'summary' => 'Delete a zone',
                        'operationId' => 'v1ZoneDelete',
                        'tags' => ['zones'],
                        'security' => [['bearerAuth' => []], ['apiKeyHeader' => []]],
                        'parameters' => [
                            [
                                'name' => 'id',
                                'in' => 'path',
                                'description' => 'Zone ID to delete',
                                'required' => true,
                                'schema' => ['type' => 'integer']
                            ]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Zone deleted successfully'
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

        error_log('Returning simplified fallback OpenAPI JSON');

        // Ensure we always return a string
        return $json !== false ? $json : '{}';
    }

    /**
     * Helper method to find all PHP files recursively in a directory
     *
     * @param string $directory The directory to scan
     * @param array &$files Array to store found files
     * @return void
     */
    private function findPhpFilesRecursively(string $directory, array &$files): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->findPhpFilesRecursively($path, $files);
            } elseif (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'php') {
                $files[] = $path;
            }
        }
    }
}
