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

/**
 * OpenAPI Configuration Class for Poweradmin API
 *
 * This class centralizes the OpenAPI documentation configuration
 * to ensure consistent documentation across the API.
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller\Api;

use OpenApi\Attributes as OA;

/**
 * OpenAPI configuration for the Poweradmin API
 */
#[OA\OpenApi(openapi: '3.0.0')]
#[OA\Info(
    title: "Poweradmin API",
    version: "1.0.0",
    description: "API for Poweradmin DNS Management",
    contact: new OA\Contact(
        email: "edmondas@poweradmin.org",
        name: "Poweradmin Development Team"
    ),
    license: new OA\License(
        name: "GPL-3.0",
        url: "https://opensource.org/licenses/GPL-3.0"
    )
)]
#[OA\Server(
    url: "/index.php",
    description: "API Server"
)]
#[OA\SecurityScheme(
    securityScheme: "bearerAuth",
    type: "http",
    scheme: "bearer",
    bearerFormat: "API Key"
)]
#[OA\SecurityScheme(
    securityScheme: "apiKeyHeader",
    type: "apiKey",
    name: "X-API-Key",
    in: "header"
)]
/**
 * This class serves as a container for OpenAPI annotations
 * and doesn't contain any actual functionality.
 */
class OpenApiConfig
{
    // This class is intentionally empty as it only serves as a container for OpenAPI annotations
}
