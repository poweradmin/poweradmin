<?php

namespace Poweradmin\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V1GoneController;

class V1GoneControllerTest extends TestCase
{
    private V1GoneController $controller;

    protected function setUp(): void
    {
        $this->controller = new V1GoneController();
    }

    public function testRespondsWithGone(): void
    {
        $this->assertEquals(410, $this->controller->buildResponse()->getStatusCode());
    }

    public function testPointsCallersAtTheSuccessorVersion(): void
    {
        $response = $this->controller->buildResponse();

        $this->assertEquals(
            '</api/v2/>; rel="successor-version"',
            $response->headers->get('Link')
        );
    }

    public function testKeepsTheV1ErrorShapeSoOldClientsCanParseIt(): void
    {
        $body = json_decode($this->controller->buildResponse()->getContent(), true);

        $this->assertTrue($body['error']);
        $this->assertStringContainsString('/api/v2', $body['message']);
    }
}
