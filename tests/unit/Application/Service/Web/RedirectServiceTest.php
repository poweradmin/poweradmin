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
 *
 */

namespace Poweradmin\Tests\Unit\Application\Service\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Service\Web\RedirectService;
use Symfony\Component\HttpFoundation\Response;

/**
 * The redirect ends the request by halting rather than by exiting, so the
 * router unwinds normally and a test can observe where it was sent.
 */
#[CoversClass(RedirectService::class)]
class RedirectServiceTest extends TestCase
{
    public function testRedirectingHaltsTheRequestAndNamesTheTarget(): void
    {
        try {
            (new RedirectService())->redirectTo('/zones/forward');
            $this->fail('The redirect must end the request');
        } catch (RequestHalted $halt) {
            $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
            $this->assertSame('/zones/forward', $halt->target);
        }
    }

    public function testSendingAResponseHaltsTheRequestAndNamesTheStatus(): void
    {
        $response = new Response('{"error":"Unauthorized"}', 401);

        ob_start();
        try {
            (new RedirectService())->send($response);
            ob_end_clean();
            $this->fail('Sending a prepared response must end the request');
        } catch (RequestHalted $halt) {
            $body = (string) ob_get_clean();
            $this->assertSame(RequestHalted::KIND_RESPONSE, $halt->kind);
            $this->assertSame('401', $halt->target);
            $this->assertStringContainsString('Unauthorized', $body);
        }
    }

    /**
     * A halt is an Error, not an Exception, so the catch (Exception) blocks that
     * wrap backend calls in the controllers cannot swallow a redirect.
     */
    public function testAHaltIsNotCaughtByAnExceptionHandler(): void
    {
        $caught = false;

        try {
            try {
                (new RedirectService())->redirectTo('/login');
            } catch (\Exception) {
                $caught = true;
            }
        } catch (RequestHalted) {
            // reached the outer handler, which is the point
        }

        $this->assertFalse($caught, 'A redirect must pass straight through catch (Exception)');
    }
}
