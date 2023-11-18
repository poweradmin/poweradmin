<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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
 * Script that handles requests to add new permission template
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;
use Poweradmin\LegacyUsers;

class AddPermTemplController extends BaseController
{
    public function run(): void
    {
        $this->checkPermission('templ_perm_add', _("You do not have the permission to add permission templates."));

        if ($this->isPost()) {
            $this->addPermTempl();
        } else {
            $this->showAddPermTempl();
        }
    }

    private function addPermTempl(): void
    {
        $v = new Valitron\Validator($_POST);
        $v->rules([
            'required' => ['templ_name'],
            'lengthMax' => [
                ['templ_name', 128],
                ['templ_descr', 1024],
            ],
            'array' => ['perm_id'],
        ]);

        if ($v->validate()) {
            LegacyUsers::add_perm_templ($_POST);
            $this->setMessage('list_perm_templ', 'success', _('The permission template has been added successfully.'));
            $this->redirect('list_perm_templ.php');
        } else {
            $this->showFirstError($v->errors());
        }
    }

    private function showAddPermTempl(): void
    {
        $this->render('add_perm_templ.html', [
            'perms_avail' => LegacyUsers::get_permissions_by_template_id(0)
        ]);
    }
}

$controller = new AddPermTemplController();
$controller->run();
