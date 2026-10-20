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

namespace Poweradmin\Application\Web;

/**
 * Where a controller's pages go: the themed chrome and body in production
 * (PageRenderer), a recorder in tests. A controller hands over the template
 * and the parameters exactly as it built them.
 */
interface PageOutputInterface
{
    /**
     * A full page: the chrome, then $template rendered with $params.
     *
     * @param array<string, mixed> $params The template variables as the controller built them
     * @param array<string, mixed> $requestData The request data, read for the current page marker
     * @param list<array<string, mixed>>|null $systemMessages Messages flashed for every page
     * @param list<array<string, mixed>>|null $scriptMessages Messages flashed for this page
     */
    public function renderPage(string $template, array $params, array $requestData, string $pageTitle, ?array $systemMessages, ?array $scriptMessages): void;

    /**
     * The chrome alone, which is how an error page shows its system messages.
     *
     * @param array<string, mixed> $requestData
     * @param list<array<string, mixed>>|null $systemMessages
     */
    public function renderChrome(array $requestData, string $pageTitle, ?array $systemMessages): void;
}
