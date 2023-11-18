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
 * Script that handles deletion of zone templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;
use Poweradmin\LegacyUsers;

require_once 'inc/toolkit.inc.php';

class DeletePermTemplController extends BaseController
{

    public function run(): void
    {
        $this->checkPermission('user_edit_templ_perm', _("You do not have the permission to delete permission templates."));

        if (isset($_GET['confirm'])) {
            $this->deletePermTempl();
        } else {
            $this->showDeletePermTempl();
        }
    }

    private function deletePermTempl(): void
    {
        $v = new Valitron\Validator($_GET);
        $v->rules([
            'required' => ['id'],
            'integer' => ['id'],
        ]);

        $perm_templ_id = htmlspecialchars($_GET['id']);
        if (!$v->validate()) {
            $this->showFirstError($v->errors());
        }

        if (LegacyUsers::delete_perm_templ($perm_templ_id)) {
            $this->setMessage('list_perm_templ', 'success', _('The permission template has been deleted successfully.'));
            $this->redirect('list_perm_templ.php');
        }

        $this->render('list_perm_templ.html', [
            'permission_templates' => LegacyUsers::list_permission_templates(),
        ]);
    }

    private function showDeletePermTempl(): void
    {
        $perm_templ_id = htmlspecialchars($_GET['id']);
        $templ_details = LegacyUsers::get_permission_template_details($perm_templ_id);

        $this->render('delete_perm_templ.html', [
            'perm_templ_id' => $perm_templ_id,
            'templ_name' => $templ_details['name'],
        ]);
    }
}

$controller = new DeletePermTemplController();
$controller->run();
