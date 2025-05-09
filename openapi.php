<?php

/**
 * OpenAPI configuration for Poweradmin API
 * 
 * This script generates OpenAPI/Swagger documentation for the Poweradmin API.
 * It should only be run in development mode or when API documentation is explicitly enabled.
 * 
 * Usage: php openapi.php
 * 
 * The generated documentation will be saved to ./docs/openapi.json
 */

// Ensure script is only run from command line
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.0 403 Forbidden');
    exit('This script can only be executed from the command line.');
}

// Include autoloader
require __DIR__ . '/vendor/autoload.php';

// Create OpenAPI generator
$paths = [
    __DIR__ . '/lib/Application/Controller/Api',
];

$openapi = \OpenApi\Generator::scan($paths, [
    'processors' => [
        new \OpenApi\Processors\MergeIntoComponents(),
        new \OpenApi\Processors\MergeIntoOpenApi(),
        new \OpenApi\Processors\CleanUnmerged(),
        new \OpenApi\Processors\BuildPaths(),
        new \OpenApi\Processors\ExpandInterfaces(),
        new \OpenApi\Processors\AugmentSchemas(),
        new \OpenApi\Processors\AugmentProperties(),
        new \OpenApi\Processors\BuildComponents(),
        new \OpenApi\Processors\CleanUnusedComponents(),
    ]
]);

// Ensure docs directory exists
if (!is_dir(__DIR__ . '/docs')) {
    mkdir(__DIR__ . '/docs', 0755, true);
}

// Save OpenAPI spec to file
file_put_contents(__DIR__ . '/docs/openapi.json', $openapi->toJson());
file_put_contents(__DIR__ . '/docs/openapi.yaml', $openapi->toYaml());

echo "OpenAPI documentation generated successfully.\n";
echo "JSON: " . __DIR__ . "/docs/openapi.json\n";
echo "YAML: " . __DIR__ . "/docs/openapi.yaml\n";