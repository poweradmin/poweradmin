<?php

namespace Poweradmin\Application\Controller\Api;

/**
 * @OA\Info(
 *     version="1.0.0",
 *     title="Poweradmin API",
 *     description="API for managing PowerDNS through Poweradmin",
 *     @OA\Contact(
 *         email="info@poweradmin.org",
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
