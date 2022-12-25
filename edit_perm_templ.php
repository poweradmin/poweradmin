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
 * Script that handles editing of permission templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;

require_once 'inc/toolkit.inc.php';
require_once 'inc/messages.inc.php';

class EditPermTemplController extends BaseController
{

    public function run(): void
    {
        $this->checkPermission('templ_perm_edit', _("You do not have the permission to edit permission templates."));

        $v = new Valitron\Validator($_GET);
        $v->rules([
            'required' => ['id'],
            'integer' => ['id'],
        ]);

        if (!$v->validate()) {
            $this->showFirstError($v->errors());
        }

        if ($this->isPost()) {
            $this->editPermTempl();
        } else {
            $this->showEditPermTempl();
        }
    }

    private function editPermTempl()
    {
        $v = new Valitron\Validator($_POST);
        $v->rules([
            'required' => ['templ_name'],
        ]);

        if ($v->validate()) {
            do_hook('update_perm_templ_details', $_POST);

            $this->setMessage('list_perm_templ', 'success', _('The permission template has been updated successfully.'));
            $this->redirect('list_perm_templ.php');
        } else {
            $this->showFirstError($v->errors());
        }
    }

    private function showEditPermTempl()
    {
        $id = htmlspecialchars($_GET['id']);
        $this->render('edit_perm_templ.html', [
            'id' => $id,
            'templ' => do_hook('get_permission_template_details', $id),
            'perms_templ' => do_hook('get_permissions_by_template_id', $id),
            'perms_avail' => do_hook('get_permissions_by_template_id')
        ]);
    }
}

$controller = new EditPermTemplController();
$controller->run();
