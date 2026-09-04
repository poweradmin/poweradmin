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

namespace Poweradmin\Application\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Answers every /api/v1 path with 410 Gone and a pointer to v2.
 *
 * Callers are unmigrated v1 clients, so the body keeps the v1 error shape
 * ({error, message}) that they already parse.
 *
 * Deliberately does not extend BaseController, whose constructor connects to the
 * database unconditionally: /api/v1 is public, so that is a connection per request
 * for a response that reads nothing.
 */
class V1GoneController
{
    private const MESSAGE = 'API v1 was removed in Poweradmin 4.5.0. Use /api/v2 instead.';

    public function run(): void
    {
        $this->buildResponse()->send();
    }

    public function buildResponse(): JsonResponse
    {
        $response = new JsonResponse([
            'error' => true,
            'message' => self::MESSAGE,
        ], 410);
        $response->headers->set('Link', '</api/v2/>; rel="successor-version"');

        return $response;
    }
}
