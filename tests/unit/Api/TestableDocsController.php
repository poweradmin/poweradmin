<?php

namespace Poweradmin\Tests\Unit\Api;

use Poweradmin\Application\Controller\Api\DocsController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
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

    public function setConfig(ConfigurationManager $config): void
    {
        $this->config = $config;
    }

    // Make the method public for testing
    public function getDocsBaseUrlPublic(): string
    {
        $reflection = new ReflectionClass(parent::class);
        $method = $reflection->getMethod('getDocsBaseUrl');
        $method->setAccessible(true);
        return $method->invoke($this);
    }
}
