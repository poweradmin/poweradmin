<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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

namespace Poweradmin;

use Twig\Environment;
use Twig\Error\Error;
use Twig\Extensions\I18nExtension;
use Twig\Loader\FilesystemLoader;

class Application {

    protected $templateRenderer;
    protected $configuration;

    public function __construct() {
        $loader = new FilesystemLoader('templates');
        $this->templateRenderer = new Environment($loader, [ 'debug' => false ]);
        $this->templateRenderer->addExtension(new I18nExtension());
        $this->configuration = new Configuration();
    }

    public function render($template, $params = []) {
        try {
            echo $this->templateRenderer->render($template, $params);
        } catch (Error $e) {
            die($e->getMessage());
        }
    }

    public function config($name) {
        return $this->configuration->get($name);
    }
}