<?php

/**
 * OpenAPI configuration for Poweradmin API
 *
 * This script generates OpenAPI/Swagger documentation for the Poweradmin API.
 * It should only be run in development mode or when API documentation is explicitly enabled.
 *
 * Usage: php tools/openapi.php
 *
 * The generated documentation will be saved to ./docs/openapi.json and ./docs/openapi.yaml
 */

// Ensure script is only run from command line
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.0 403 Forbidden');
    exit('This script can only be executed from the command line.');
}

use OpenApi\Attributes as OA;
use OpenApi\Generator;

// Include autoloader
require __DIR__ . '/../vendor/autoload.php';

// Check if OpenApi\Generator class is available
if (!class_exists('OpenApi\\Generator')) {
    die(
        "Error: OpenApi\\Generator class not found.\n" .
        "Please run 'composer install' to install the required dependencies.\n"
    );
}

// This file is now primarily for CLI generation of OpenAPI specs
// The web interface should use the DocsController instead

echo "Starting OpenAPI documentation generation...\n";
echo "Note: For web usage, access the DocsController endpoint instead.\n\n";

try {
    // Define OpenAPI tags for organization
    $tags = [
        new OA\Tag(name: 'zones', description: 'Zone and DNS record management'),
        new OA\Tag(name: 'users', description: 'User authentication and verification')
    ];

    // Configure OpenAPI generator with minimal options
    // Most of the configuration is now in DocsController attributes
    $options = [
        'validate' => true,
        'tags' => $tags,
        'openapi' => '3.0.0'  // Explicitly specify OpenAPI version
    ];

    // Scan the codebase for OpenAPI annotations/attributes
    $openapi = Generator::scan([
        __DIR__ . '/../lib/Application/Controller/Api',  // All API controllers
    ], $options);

    // Ensure docs directory exists
    if (!is_dir(__DIR__ . '/../docs')) {
        mkdir(__DIR__ . '/../docs', 0755, true);
    }

    // Save OpenAPI spec to JSON file with pretty print
    $jsonOutput = $openapi->toJson();
    file_put_contents(__DIR__ . '/../docs/openapi.json', $jsonOutput);
    echo "OpenAPI JSON documentation generated successfully.\n";
    echo "JSON: " . __DIR__ . "/../docs/openapi.json\n";

    // Save OpenAPI spec to YAML file
    $yamlOutput = $openapi->toYaml();
    file_put_contents(__DIR__ . '/../docs/openapi.yaml', $yamlOutput);
    echo "YAML: " . __DIR__ . "/../docs/openapi.yaml\n";

    echo "OpenAPI documentation completed.\n";

} catch (\Exception $e) {
    echo "Error generating OpenAPI documentation: " . $e->getMessage() . "\n";
    echo "Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}