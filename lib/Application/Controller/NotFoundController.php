<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025  Poweradmin Development Team
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

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;

class NotFoundController extends BaseController
{
    public function __construct(array $request)
    {
        // Disable authentication for 404 pages
        parent::__construct($request, false);
    }

    public function run(): void
    {
        // Set 404 HTTP status code
        http_response_code(404);

        // Check if the request expects JSON
        if (self::expectsJson()) {
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Not Found',
                'message' => 'The requested resource was not found',
                'status' => 404
            ]);
            return;
        }

        // For regular web requests, show 404 page
        $this->render('404.html', [
            'title' => _('Page Not Found')
        ]);
    }
}
