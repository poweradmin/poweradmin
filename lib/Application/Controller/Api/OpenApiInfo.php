<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

/**
 * @OA\Info(
 *     version="1.0.0",
 *     title="Poweradmin API",
 *     description="API for managing PowerDNS through Poweradmin",
 *     @OA\Contact(
 *         email="edmondas@poweradmin.org",
 *         name="Poweradmin Development Team"
 *     ),
 *     @OA\License(
 *         name="GNU General Public License v3.0",
 *         url="https://www.gnu.org/licenses/gpl-3.0.en.html"
 *     )
 * )
 *
 * @OA\Server(
 *     url="/",
 *     description="Default Server"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="API key"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="apiKeyHeader",
 *     type="apiKey",
 *     in="header",
 *     name="X-API-Key"
 * )
 */
class OpenApiInfo
{
    // This class only contains annotations for OpenAPI documentation
}
