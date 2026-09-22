<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\Internal;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\Internal\UserPreferencesController;
use Poweradmin\Domain\Model\UserPreference;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\User\UserPreferenceService;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Poweradmin\Infrastructure\Session\PhpSession;

/**
 * Every handler of /api/internal/user-preferences answers with a returned
 * JsonResponse that run() sends once, instead of sending and exiting itself.
 */
#[CoversClass(UserPreferencesController::class)]
class UserPreferencesControllerTest extends TestCase
{
    private const USER_ID = 7;

    /** @var UserPreferenceService&MockObject */
    private UserPreferenceService $preferences;

    private array $sessionBackup = [];

    protected function setUp(): void
    {
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = [SessionKeys::USERID => self::USER_ID];
        $this->preferences = $this->createMock(UserPreferenceService::class);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
    }

    /** @param array<string, mixed> $json */
    private function respond(string $method, string $uri, array $json = []): JsonResponse
    {
        $controller = (new ReflectionClass(UserPreferencesController::class))->newInstanceWithoutConstructor();
        $request = Request::create($uri, $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], $json === [] ? null : json_encode($json));

        (new ReflectionProperty($controller, 'request'))->setValue($controller, $request);
        (new ReflectionProperty($controller, 'userPreferenceService'))->setValue($controller, $this->preferences);
        (new ReflectionProperty($controller, 'userContextService'))->setValue($controller, new UserContextService(new PhpSession()));

        return (new ReflectionMethod($controller, 'respond'))->invoke($controller);
    }

    /** @return array<string, mixed> */
    private function body(JsonResponse $response): array
    {
        return json_decode((string)$response->getContent(), true);
    }

    public function testWithoutASessionUserEveryVerbIs401(): void
    {
        $_SESSION = [];

        $response = $this->respond('GET', '/api/internal/user-preferences');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['error' => 'Unauthorized'], $this->body($response));
    }

    public function testAnUnknownVerbIs405(): void
    {
        $response = $this->respond('PATCH', '/api/internal/user-preferences');

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame(['error' => 'Method not allowed'], $this->body($response));
    }

    public function testGetWithoutAKeyListsAllPreferences(): void
    {
        $this->preferences->method('getAllPreferences')->with(self::USER_ID)->willReturn(['rows_per_page' => '25']);

        $response = $this->respond('GET', '/api/internal/user-preferences');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['preferences' => ['rows_per_page' => '25']], $this->body($response));
    }

    public function testGetWithAKnownKeyReturnsThatPreference(): void
    {
        $this->preferences->method('getPreference')->with(self::USER_ID, UserPreference::KEY_DEFAULT_TTL)->willReturn('300');

        $response = $this->respond('GET', '/api/internal/user-preferences?key=' . UserPreference::KEY_DEFAULT_TTL);

        $this->assertSame(['key' => UserPreference::KEY_DEFAULT_TTL, 'value' => '300'], $this->body($response));
    }

    public function testGetWithAnUnknownKeyIs400(): void
    {
        $this->preferences->expects($this->never())->method('getPreference');

        $response = $this->respond('GET', '/api/internal/user-preferences?key=nope');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['error' => 'Invalid preference key'], $this->body($response));
    }

    public function testAnUpdateWithoutKeyOrValueIs400(): void
    {
        $this->preferences->expects($this->never())->method('setPreference');

        $response = $this->respond('POST', '/api/internal/user-preferences', ['key' => 'rows_per_page']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['error' => 'Missing key or value'], $this->body($response));
    }

    public function testAnUpdateStoresThePreferenceAndEchoesIt(): void
    {
        $this->preferences->expects($this->once())->method('setPreference')->with(self::USER_ID, 'rows_per_page', '50');

        $response = $this->respond('PUT', '/api/internal/user-preferences', ['key' => 'rows_per_page', 'value' => '50']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['success' => true, 'key' => 'rows_per_page', 'value' => '50'], $this->body($response));
    }

    public function testARejectedUpdateCarriesTheServiceMessage(): void
    {
        $this->preferences->method('setPreference')->willThrowException(new InvalidArgumentException('bad value'));

        $response = $this->respond('POST', '/api/internal/user-preferences', ['key' => 'rows_per_page', 'value' => 'x']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['error' => 'bad value'], $this->body($response));
    }

    public function testADeleteWithoutAKeyIs400(): void
    {
        $response = $this->respond('DELETE', '/api/internal/user-preferences');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['error' => 'Missing key parameter'], $this->body($response));
    }

    public function testADeleteWithAnUnknownKeyIs400(): void
    {
        $this->preferences->expects($this->never())->method('resetPreference');

        $response = $this->respond('DELETE', '/api/internal/user-preferences?key=nope');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['error' => 'Invalid preference key'], $this->body($response));
    }

    public function testADeleteResetsThePreference(): void
    {
        $this->preferences->expects($this->once())->method('resetPreference')->with(self::USER_ID, 'rows_per_page');

        $response = $this->respond('DELETE', '/api/internal/user-preferences?key=rows_per_page');

        $this->assertSame(['success' => true, 'key' => 'rows_per_page'], $this->body($response));
    }
}
