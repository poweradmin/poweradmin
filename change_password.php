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

/**
 * Script that handles user password changes
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;
use Valitron\Validator;

require_once 'inc/toolkit.inc.php';

class ChangePasswordController extends BaseController {

    public function run(): void
    {
        if ($this->isPost()) {
            $v = new Validator($_POST);
            $v->rules([
                'required' => [
                    ['old_password'],
                    ['new_password'],
                    ['new_password2'],
                ]
            ]);

            if ($v->validate()) {
                do_hook('change_user_pass', $_POST);
            } else {
                $this->showError($v->errors());
            }
        }

        $this->render('change_password.html', []);
    }
}

$controller = new ChangePasswordController();
$controller->run();
