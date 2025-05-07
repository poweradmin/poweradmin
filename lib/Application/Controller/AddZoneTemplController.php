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
 * Script that handles requests to add new zone templates
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

class AddZoneTemplController extends BaseController
{

    public function __construct(array $request)
    {
        parent::__construct($request);
    }
    public function run(): void
    {
        $this->checkPermission('zone_master_add', _("You do not have the permission to add a zone template."));

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->addZoneTemplate();
        } else {
            $this->showAddZoneTemplate();
        }
    }

    private function showAddZoneTemplate(): void
    {
        $this->render('add_zone_templ.html', [
            'user_name' => UserManager::getFullnameFromUserId($this->db, $_SESSION['userid']) ?: $_SESSION['userlogin'],
            'perm_is_godlike' => UserManager::verifyPermission($this->db, 'user_is_ueberuser'),
        ]);
    }

    private function addZoneTemplate(): void
    {
        $constraints = [
            'templ_name' => [
                new Assert\NotBlank(),
                new Assert\Length(['max' => 128])
            ],
            'templ_descr' => [
                new Assert\Length(['max' => 1024])
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_POST)) {
            $this->showFirstValidationError($_POST);
        }

        $zoneTemplate = new ZoneTemplate($this->db, $this->getConfig());
        if ($zoneTemplate->addZoneTempl($_POST, $_SESSION['userid'])) {
            $this->setMessage('list_zone_templ', 'success', _('Zone template has been added successfully.'));
            $this->redirect('index.php', ['page' => 'list_zone_templ']);
        } else {
            $this->render('add_zone_templ.html', [
                'user_name' => UserManager::getFullnameFromUserId($this->db, $_SESSION['userid']) ?: $_SESSION['userlogin'],
                'templ_name' => htmlspecialchars($_POST['templ_name']),
                'templ_descr' => htmlspecialchars($_POST['templ_descr']),
                'perm_is_godlike' => UserManager::verifyPermission($this->db, 'user_is_ueberuser')
            ]);
        }
    }
}
