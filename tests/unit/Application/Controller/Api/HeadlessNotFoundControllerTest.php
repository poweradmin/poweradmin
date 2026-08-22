<?php

namespace Poweradmin\Tests\Unit\Application\Controller\Api;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\HeadlessNotFoundController;

/**
 * The responder that answers every miss on a headless install.
 */
class HeadlessNotFoundControllerTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;

        parent::tearDown();
    }

    public function testWebPathGetsTheLegacyErrorShape(): void
    {
        $_SERVER['REQUEST_URI'] = '/login';
        $response = (new HeadlessNotFoundController())->buildResponse();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame(
            ['error' => 'Not Found', 'message' => 'The requested resource was not found', 'status' => 404],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testV2PathKeepsTheWrappedEnvelope(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v2/nope';
        $response = (new HeadlessNotFoundController())->buildResponse();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            ['success' => false, 'data' => null, 'message' => 'The requested resource was not found'],
            json_decode((string) $response->getContent(), true)
        );
    }

    /**
     * The envelope is chosen from the path, not the query string, so a web URL that merely
     * mentions the API does not get the v2 shape.
     */
    public function testQueryStringDoesNotSelectTheV2Envelope(): void
    {
        $_SERVER['REQUEST_URI'] = '/login?next=/api/v2/zones';
        $response = (new HeadlessNotFoundController())->buildResponse();

        $this->assertArrayHasKey('error', json_decode((string) $response->getContent(), true));
    }
}
