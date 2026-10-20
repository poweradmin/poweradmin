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

namespace Poweradmin\Application\Controller\Api\V2;

use OpenApi\Attributes as OA;

/**
 * Carries the OpenAPI spec-level attributes (info, servers, security schemes)
 * for the v2 API. Annotation-only: the class holds no runtime code and exists
 * so the spec root does not live on an arbitrary endpoint controller. The
 * docs generator scans this directory and picks the attributes up from here.
 */
#[OA\OpenApi(
    info: new OA\Info(
        version: '2.0.0',
        description: 'RESTful API for Poweradmin DNS Management (v2 - with wrapped responses)',
        title: 'Poweradmin API v2'
    ),
    servers: [
        new OA\Server(url: '/api', description: 'API Server')
    ]
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    bearerFormat: 'API Key',
    scheme: 'bearer'
)]
#[OA\SecurityScheme(
    securityScheme: 'apiKeyHeader',
    type: 'apiKey',
    name: 'X-API-Key',
    in: 'header'
)]
class OpenApiSpec
{
}
