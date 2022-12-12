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
 * Script that handles deletion of zone templates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\BaseController;

require_once 'inc/toolkit.inc.php';
require_once 'inc/message.inc.php';

class DeletePermTemplController extends BaseController
{

    public function run(): void
    {
        $this->checkPermission('user_edit_templ_perm', ERR_PERM_DEL_PERM_TEMPL);

        if (isset($_GET['confirm'])) {
            $this->deletePermTempl();
        } else {
            $this->showDeletePermTempl();
        }
    }

    private function deletePermTempl()
    {
        $v = new Valitron\Validator($_GET);
        $v->rules([
            'required' => ['id'],
            'integer' => ['id'],
        ]);

        $perm_templ_id = htmlspecialchars($_GET['id']);
        if ($v->validate()) {
            if (do_hook('delete_perm_templ', $perm_templ_id)) {
                success(SUC_PERM_TEMPL_DEL);
            }

            $this->render('list_perm_templ.html', [
                'permission_templates' => do_hook('list_permission_templates')
            ]);
        } else {
            $this->showError($v->errors());
        }
    }

    private function showDeletePermTempl()
    {
        $perm_templ_id = htmlspecialchars($_GET['id']);
        $templ_details = do_hook('get_permission_template_details', $perm_templ_id);

        $this->render('delete_perm_templ.html', [
            'perm_templ_id' => $perm_templ_id,
            'templ_name' => $templ_details['name'],
        ]);
    }
}

$controller = new DeletePermTemplController();
$controller->run();
