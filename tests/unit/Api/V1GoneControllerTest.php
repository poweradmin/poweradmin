<?php

namespace Poweradmin\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

class V1GoneControllerTest extends TestCase
{
    private TestableV1GoneController $controller;

    protected function setUp(): void
    {
        $this->controller = new TestableV1GoneController();
    }

    public function testRespondsWithGone(): void
    {
        $this->assertEquals(410, $this->controller->buildResponsePublic()->getStatusCode());
    }

    public function testPointsCallersAtTheSuccessorVersion(): void
    {
        $response = $this->controller->buildResponsePublic();

        $this->assertEquals(
            '</api/v2/>; rel="successor-version"',
            $response->headers->get('Link')
        );
    }

    public function testKeepsTheV1ErrorShapeSoOldClientsCanParseIt(): void
    {
        $body = json_decode($this->controller->buildResponsePublic()->getContent(), true);

        $this->assertTrue($body['error']);
        $this->assertStringContainsString('/api/v2', $body['message']);
    }
}
