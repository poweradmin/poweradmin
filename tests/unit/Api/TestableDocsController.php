<?php

namespace unit\Api;

use Poweradmin\Application\Controller\Api\DocsController;
use ReflectionClass;

/**
 * Test double for DocsController to avoid initialization issues
 */
class TestableDocsController extends DocsController
{
    public function __construct()
    {
        // Skip parent constructor to avoid database initialization
    }

    public function run(): void
    {
        // Empty implementation for testing
    }

    // Make the method public for testing
    public function getValidatedHostPublic(): string
    {
        $reflection = new ReflectionClass(parent::class);
        $method = $reflection->getMethod('getValidatedHost');
        $method->setAccessible(true);
        return $method->invoke($this);
    }
}
