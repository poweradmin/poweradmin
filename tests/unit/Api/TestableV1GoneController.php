<?php

namespace Poweradmin\Tests\Unit\Api;

use Poweradmin\Application\Controller\Api\V1GoneController;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Test double for V1GoneController to avoid initialization issues
 */
class TestableV1GoneController extends V1GoneController
{
    public function __construct()
    {
        // Skip parent constructor to avoid database initialization
    }

    public function buildResponsePublic(): JsonResponse
    {
        return $this->buildResponse();
    }
}
