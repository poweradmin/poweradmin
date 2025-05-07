<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
 * Script that handles zone template deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Symfony\Component\Validator\Constraints as Assert;

class DeleteZoneTemplController extends BaseController
{
    public function __construct(array $request)
    {
        parent::__construct($request);
    }
    public function run(): void
    {
        $constraints = [
            'id' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_GET)) {
            $this->showFirstValidationError($_GET);
        }

        $zone_templ_id = htmlspecialchars($_GET['id']);
        $owner = ZoneTemplate::getZoneTemplIsOwner($this->db, $zone_templ_id, $_SESSION['userid']);
        $perm_godlike = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
        $perm_master_add = UserManager::verifyPermission($this->db, 'zone_master_add');

        $this->checkCondition(!($perm_godlike || $perm_master_add && $owner), _("You do not have the permission to delete zone templates."));

        if (isset($_GET['confirm'])) {
            $this->deleteZoneTempl();
        } else {
            $this->showDeleteZoneTempl();
        }
    }

    private function deleteZoneTempl(): void
    {
        $constraints = [
            'id' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ]
        ];

        $this->setValidationConstraints($constraints);

        if ($this->doValidateRequest($_GET)) {
            $zone_templ_id = htmlspecialchars($_GET['id']);
            $zoneTemplate = new ZoneTemplate($this->db, $this->config);
            $zoneTemplate->deleteZoneTempl($zone_templ_id);

            $this->setMessage('list_zone_templ', 'success', _('Zone template has been deleted successfully.'));
            $this->redirect('index.php', ['page' => 'list_zone_templ']);
        } else {
            $this->showFirstValidationError($_GET);
        }
    }

    private function showDeleteZoneTempl(): void
    {
        $zone_templ_id = htmlspecialchars($_GET['id']);
        $templ_details = ZoneTemplate::getZoneTemplDetails($this->db, $zone_templ_id);

        $this->render('delete_zone_templ.html', [
            'templ_name' => $templ_details['name'],
            'zone_templ_id' => $zone_templ_id,
        ]);
    }
}
