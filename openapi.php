<?php

/**
 * OpenAPI configuration for Poweradmin API
 * 
 * This script generates OpenAPI/Swagger documentation for the Poweradmin API.
 * It should only be run in development mode or when API documentation is explicitly enabled.
 * 
 * Usage: php openapi.php
 * 
 * The generated documentation will be saved to ./docs/openapi.json and ./docs/openapi.yaml
 */

// Ensure script is only run from command line
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.0 403 Forbidden');
    exit('This script can only be executed from the command line.');
}

// Include autoloader
require __DIR__ . '/vendor/autoload.php';

use OpenApi\Generator;
use OpenApi\Annotations as OA;

// Define the OpenAPI specification with all the necessary components
$spec = [
    'openapi' => '3.0.0',
    'info' => [
        'title' => 'Poweradmin API',
        'version' => '1.0.0',
        'description' => 'API for managing PowerDNS through Poweradmin',
        'contact' => [
            'name' => 'Poweradmin Development Team',
            'email' => 'info@poweradmin.org'
        ],
        'license' => [
            'name' => 'GNU General Public License v3.0',
            'url' => 'https://www.gnu.org/licenses/gpl-3.0.en.html'
        ]
    ],
    'servers' => [
        [
            'url' => '/',
            'description' => 'Default Server'
        ]
    ],
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'API key'
            ],
            'apiKeyHeader' => [
                'type' => 'apiKey',
                'in' => 'header',
                'name' => 'X-API-Key'
            ]
        ]
    ],
    'paths' => []
];

echo "Starting OpenAPI documentation generation...\n";

try {
    // Try scanning, but don't use the result since the annotations aren't being properly processed
    // This can be improved in the future when the annotation scanner is working properly
    Generator::scan([
        __DIR__ . '/lib/Application/Controller/Api',
    ], ['validate' => false]);
    
    echo "Adding API paths manually from controller implementations:\n";
    
    // V1 Zone API
    $spec['paths']['/v1/zone'] = [
        'get' => [
            'summary' => 'List all accessible zones or get a specific zone',
            'description' => 'Use with action=list to list all zones or action=get with id/name parameters to get a specific zone',
            'parameters' => [
                [
                    'name' => 'action',
                    'in' => 'query',
                    'required' => true,
                    'schema' => ['type' => 'string', 'enum' => ['list', 'get']]
                ],
                [
                    'name' => 'id',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'integer']
                ],
                [
                    'name' => 'name',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'string']
                ]
            ],
            'responses' => [
                '200' => ['description' => 'Success'],
                '401' => ['description' => 'Unauthorized'],
                '404' => ['description' => 'Not found']
            ],
            'security' => [
                ['bearerAuth' => []],
                ['apiKeyHeader' => []]
            ]
        ],
        'post' => [
            'summary' => 'Create a new zone or add a record',
            'description' => 'Use with action=create to create a zone, action=add_record to add a DNS record, or action=set_permissions to set domain permissions',
            'parameters' => [
                [
                    'name' => 'action',
                    'in' => 'query',
                    'required' => true,
                    'schema' => ['type' => 'string', 'enum' => ['create', 'add_record', 'set_permissions']]
                ]
            ],
            'responses' => [
                '200' => ['description' => 'Success'],
                '201' => ['description' => 'Created successfully'],
                '400' => ['description' => 'Bad request'],
                '401' => ['description' => 'Unauthorized']
            ],
            'security' => [
                ['bearerAuth' => []],
                ['apiKeyHeader' => []]
            ]
        ],
        'put' => [
            'summary' => 'Update a zone or record',
            'description' => 'Use with action=update to update a zone or action=update_record to update a record',
            'parameters' => [
                [
                    'name' => 'action',
                    'in' => 'query',
                    'required' => true,
                    'schema' => ['type' => 'string', 'enum' => ['update', 'update_record']]
                ]
            ],
            'responses' => [
                '200' => ['description' => 'Success'],
                '400' => ['description' => 'Bad request'],
                '401' => ['description' => 'Unauthorized'],
                '404' => ['description' => 'Not found']
            ],
            'security' => [
                ['bearerAuth' => []],
                ['apiKeyHeader' => []]
            ]
        ],
        'delete' => [
            'summary' => 'Delete a zone',
            'description' => 'Use with action=delete to delete a zone',
            'parameters' => [
                [
                    'name' => 'action',
                    'in' => 'query',
                    'required' => true,
                    'schema' => ['type' => 'string', 'enum' => ['delete']]
                ],
                [
                    'name' => 'id',
                    'in' => 'query',
                    'required' => true,
                    'schema' => ['type' => 'integer']
                ]
            ],
            'responses' => [
                '200' => ['description' => 'Success'],
                '400' => ['description' => 'Bad request'],
                '401' => ['description' => 'Unauthorized'],
                '404' => ['description' => 'Not found']
            ],
            'security' => [
                ['bearerAuth' => []],
                ['apiKeyHeader' => []]
            ]
        ]
    ];
    
    // V1 User API
    $spec['paths']['/v1/user'] = [
        'get' => [
            'summary' => 'Verify user credentials and API key',
            'description' => 'Verifies that the API key is valid and returns information about the user',
            'parameters' => [
                [
                    'name' => 'action',
                    'in' => 'query',
                    'required' => true,
                    'schema' => ['type' => 'string', 'enum' => ['verify']]
                ]
            ],
            'responses' => [
                '200' => ['description' => 'Success'],
                '401' => ['description' => 'Unauthorized']
            ],
            'security' => [
                ['bearerAuth' => []],
                ['apiKeyHeader' => []]
            ]
        ]
    ];

    // Add request bodies for POST and PUT operations
    $recordRequestBody = [
        'required' => true,
        'content' => [
            'application/json' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'zone_id' => ['type' => 'integer', 'description' => 'ID of the zone/domain'],
                        'name' => ['type' => 'string', 'description' => 'Record name (e.g., www.example.com)'],
                        'type' => ['type' => 'string', 'description' => 'DNS record type (e.g., A, AAAA, CNAME)'],
                        'content' => ['type' => 'string', 'description' => 'Record content/value'],
                        'ttl' => ['type' => 'integer', 'description' => 'Time to live in seconds'],
                        'prio' => ['type' => 'integer', 'description' => 'Priority (for MX/SRV records)']
                    ],
                    'required' => ['zone_id', 'name', 'type', 'content']
                ]
            ]
        ]
    ];
    
    $updateRecordRequestBody = [
        'required' => true,
        'content' => [
            'application/json' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'record_id' => ['type' => 'integer', 'description' => 'ID of the record to update'],
                        'name' => ['type' => 'string', 'description' => 'New record name'],
                        'type' => ['type' => 'string', 'description' => 'DNS record type'],
                        'content' => ['type' => 'string', 'description' => 'New record content/value'],
                        'ttl' => ['type' => 'integer', 'description' => 'New time to live in seconds'],
                        'prio' => ['type' => 'integer', 'description' => 'New priority (for MX/SRV records)'],
                        'disabled' => ['type' => 'integer', 'description' => 'Disabled flag (0=enabled, 1=disabled)']
                    ],
                    'required' => ['record_id']
                ]
            ]
        ]
    ];
    
    $domainPermissionRequestBody = [
        'required' => true,
        'content' => [
            'application/json' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'domain_id' => ['type' => 'integer', 'description' => 'Domain ID to assign permissions for'],
                        'user_id' => ['type' => 'integer', 'description' => 'User ID to assign as domain owner']
                    ],
                    'required' => ['domain_id', 'user_id']
                ]
            ]
        ]
    ];
    
    // Add request bodies to operations
    $spec['paths']['/v1/zone']['post']['requestBody'] = $recordRequestBody;
    $spec['paths']['/v1/zone']['put']['requestBody'] = $updateRecordRequestBody;
    
    // Also add tags for better organization
    $spec['tags'] = [
        ['name' => 'zones', 'description' => 'Zone and DNS record management'],
        ['name' => 'users', 'description' => 'User authentication and verification']
    ];
    
    foreach ($spec['paths']['/v1/zone'] as &$method) {
        $method['tags'] = ['zones'];
    }
    
    foreach ($spec['paths']['/v1/user'] as &$method) {
        $method['tags'] = ['users'];
    }

    // Ensure docs directory exists
    if (!is_dir(__DIR__ . '/docs')) {
        mkdir(__DIR__ . '/docs', 0755, true);
    }

    // Save OpenAPI spec to JSON file with pretty print
    $jsonOutput = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents(__DIR__ . '/docs/openapi.json', $jsonOutput);
    echo "OpenAPI JSON documentation generated successfully.\n";
    echo "JSON: " . __DIR__ . "/docs/openapi.json\n";

    // Convert to YAML using the json_decode/encode trick if YAML extension not available
    if (function_exists('yaml_emit')) {
        file_put_contents(__DIR__ . '/docs/openapi.yaml', yaml_emit($spec));
    } else {
        // Basic JSON to YAML conversion (very simplified)
        $yaml = "";
        $indent = 0;
        $previousKey = "";
        
        // Convert JSON to a simple YAML format (this is very basic)
        function jsonToSimpleYaml($data, $indent = 0) {
            $yaml = "";
            $spaces = str_repeat('  ', $indent);
            
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $yaml .= "$spaces$key:\n";
                    $yaml .= jsonToSimpleYaml($value, $indent + 1);
                } else {
                    $value = is_string($value) ? "'$value'" : $value;
                    $yaml .= "$spaces$key: $value\n";
                }
            }
            
            return $yaml;
        }
        
        $yaml = jsonToSimpleYaml($spec);
        file_put_contents(__DIR__ . '/docs/openapi.yaml', $yaml);
    }
    
    echo "YAML: " . __DIR__ . "/docs/openapi.yaml\n";
    echo "OpenAPI documentation completed.\n";
    
} catch (\Exception $e) {
    echo "Error generating OpenAPI documentation: " . $e->getMessage() . "\n";
    echo "Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}