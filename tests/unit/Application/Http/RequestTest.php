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

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application\Http;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\Request;

class RequestTest extends TestCase
{
    private array $originalGet;
    private array $originalPost;
    private array $originalServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
        $this->originalServer = $_SERVER;
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
        $_SERVER = $this->originalServer;
        parent::tearDown();
    }

    public function testConstructorDoesNotThrowInCliContext(): void
    {
        $request = new Request();
        $this->assertInstanceOf(Request::class, $request);
    }

    public function testGetQueryParamReturnsValue(): void
    {
        $_GET = ['type' => 'reverse', 'page' => '2'];
        $request = new Request();

        $this->assertSame('reverse', $request->getQueryParam('type'));
        $this->assertSame('2', $request->getQueryParam('page'));
    }

    public function testGetQueryParamReturnsDefaultForMissingKey(): void
    {
        $_GET = ['type' => 'reverse'];
        $request = new Request();

        $this->assertNull($request->getQueryParam('missing'));
        $this->assertSame('fallback', $request->getQueryParam('missing', 'fallback'));
    }

    public function testGetPostParamReturnsValue(): void
    {
        $_POST = ['domain' => 'example.com', 'owner' => '1'];
        $request = new Request();

        $this->assertSame('example.com', $request->getPostParam('domain'));
        $this->assertSame('1', $request->getPostParam('owner'));
    }

    public function testGetPostParamReturnsDefaultForMissingKey(): void
    {
        $request = new Request();

        $this->assertNull($request->getPostParam('missing'));
        $this->assertSame('default', $request->getPostParam('missing', 'default'));
    }

    public function testPostMutationAfterConstructionRequiresRefresh(): void
    {
        // The wrapper is a snapshot; refresh() is the explicit way to
        // pick up superglobal changes made after construction.
        $request = new Request();
        $_POST['late'] = 'value';

        $this->assertNull($request->getPostParam('late'));

        $request->refresh();
        $this->assertSame('value', $request->getPostParam('late'));
    }

    public function testGetPostParamsReturnsArray(): void
    {
        $_POST = ['a' => '1', 'b' => '2'];
        $request = new Request();

        $this->assertSame(['a' => '1', 'b' => '2'], $request->getPostParams());
    }

    public function testGetQueryParamsReturnsArray(): void
    {
        $_GET = ['x' => 'y'];
        $request = new Request();

        $this->assertSame(['x' => 'y'], $request->getQueryParams());
    }

    public function testGetPostParamHandlesArrayValues(): void
    {
        // Multi-select form fields (e.g. groups[]) submit as PHP arrays.
        $_POST = ['groups' => ['10', '20', '30']];
        $request = new Request();

        $this->assertSame(['10', '20', '30'], $request->getPostParam('groups'));
    }

    public function testGetServerParamReturnsValue(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/zones/add/master';
        $request = new Request();

        $this->assertSame('POST', $request->getServerParam('REQUEST_METHOD'));
        $this->assertSame('/zones/add/master', $request->getServerParam('REQUEST_URI'));
        $this->assertNull($request->getServerParam('missing'));
    }

    public function testGetMethodDefaultsToGet(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        $request = new Request();

        $this->assertSame('GET', $request->getMethod());
    }

    public function testGetMethodReturnsServerValue(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request = new Request();

        $this->assertSame('POST', $request->getMethod());
    }

    public function testGetUriDefaultsToRoot(): void
    {
        unset($_SERVER['REQUEST_URI']);
        $request = new Request();

        $this->assertSame('/', $request->getUri());
    }

    public function testRefreshRereadsGlobals(): void
    {
        $_GET = ['v' => '1'];
        $request = new Request();
        $this->assertSame('1', $request->getQueryParam('v'));

        $_GET = ['v' => '2'];
        $request->refresh();
        $this->assertSame('2', $request->getQueryParam('v'));
    }
}
