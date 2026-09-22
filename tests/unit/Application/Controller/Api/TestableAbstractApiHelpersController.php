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

namespace Poweradmin\Tests\Unit\Application\Controller\Api;

use Poweradmin\Application\Controller\Api\AbstractApiController;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Widens access to the protected body-decoding and 405 helpers under test;
 * never constructed normally, so the API-enabled gate and authentication are
 * not involved.
 */
class TestableAbstractApiHelpersController extends AbstractApiController
{
    public function run(): void
    {
    }

    public function callGetValidatedJsonBody(): ?array
    {
        return $this->getValidatedJsonBody();
    }

    public function callMethodNotAllowed(array $allowedMethods): JsonResponse
    {
        return $this->methodNotAllowed($allowedMethods);
    }

    public function callInputString(array $input, string $key, ?string $default = null): ?string
    {
        return $this->inputString($input, $key, $default);
    }

    public function callInputInt(array $input, string $key, ?int $default = null): ?int
    {
        return $this->inputInt($input, $key, $default);
    }

    public function callInputBool(array $input, string $key, ?bool $default = null): ?bool
    {
        return $this->inputBool($input, $key, $default);
    }

    public function callInputIntFromBool(array $input, string $key, ?int $default = 0): ?int
    {
        return $this->inputIntFromBool($input, $key, $default);
    }

    public function callSendAndHalt(JsonResponse $response): never
    {
        $this->sendAndHalt($response);
    }
}
