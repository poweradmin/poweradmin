<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
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

include_once(__DIR__ . '/../../../inc/config-defaults.inc.php');
@include_once(__DIR__ . '/../../../inc/config.inc.php');

class SwitchThemeController extends BaseController
{
    private const DEFAULT_THEME = 'ignite';

    private array $get;
    private array $server;

    public function __construct(array $request)
    {
        // parent::__construct($request); FIXME: this doesn't work

        $this->get = $_GET;
        $this->server = $_SERVER;
    }

    public function run(): void
    {
        $selectedTheme = $this->getSelectedTheme();
        $this->setThemeCookie($selectedTheme);
        $this->redirectToPreviousPage();
    }

    private function getSelectedTheme(): string
    {
        $theme = htmlspecialchars($this->get['theme']);
        return $theme === 'spark' ? 'spark' : self::DEFAULT_THEME;
    }

    private function setThemeCookie(string $selectedTheme): void
    {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie("theme", $selectedTheme, [
            'secure' => $secure,
            'httponly' => true,
        ]);
    }

    private function redirectToPreviousPage(): void
    {
        $previousScriptName = 'index.php';

        if (isset($this->server['HTTP_REFERER'])) {
            $previousScriptUrlParts = parse_url($this->server['HTTP_REFERER']);
            parse_str($previousScriptUrlParts['query'], $previousScriptUrlQueryParts);
            $previousScriptName = $previousScriptUrlQueryParts['page'] === 'switch_theme' ? 'index.php' : 'index.php?page=' . $previousScriptUrlQueryParts['page'];
        }

        $previousScriptName = htmlspecialchars($previousScriptName, ENT_QUOTES, 'UTF-8');

        $this->redirect($previousScriptName);
    }
}
