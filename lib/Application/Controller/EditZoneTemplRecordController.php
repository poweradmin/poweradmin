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
 * Script that handles records editing in zone templates
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
use Poweradmin\Domain\Service\RecordTypeService;
use Symfony\Component\Validator\Constraints as Assert;

class EditZoneTemplRecordController extends BaseController
{
    private RecordTypeService $recordTypeService;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->recordTypeService = new RecordTypeService($this->getConfig());
    }

    public function run(): void
    {
        $constraints = [
            'id' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ],
            'zone_templ_id' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_GET)) {
            $this->showFirstValidationError($_GET);
        }

        $record_id = htmlspecialchars($_GET['id']);
        $zone_templ_id = htmlspecialchars($_GET['zone_templ_id']);

        $owner = ZoneTemplate::getZoneTemplIsOwner($this->db, $zone_templ_id, $_SESSION['userid']);
        $perm_godlike = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
        $perm_templ_edit = UserManager::verifyPermission($this->db, 'zone_templ_edit');
        $this->checkCondition(!($perm_godlike || $perm_templ_edit && $owner), _("You do not have the permission to edit zone template records."));

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->updateZoneTemplateRecord($zone_templ_id);
        }

        $this->showZoneTemplateRecordForm($record_id, $zone_templ_id);
    }

    public function showZoneTemplateRecordForm(string $record_id, string $zone_templ_id): void
    {
        $record = ZoneTemplate::getZoneTemplRecordFromId($this->db, $record_id);

        $this->render('edit_zone_templ_record.html', [
            'record' => $record,
            'zone_templ_id' => $zone_templ_id,
            'record_id' => $record_id,
            'templ_details' => ZoneTemplate::getZoneTemplDetails($this->db, $zone_templ_id),
            'record_types' => $this->recordTypeService->getAllTypes(),
        ]);
    }

    public function updateZoneTemplateRecord(string $zone_templ_id): void
    {
        $constraints = [
            'name' => [
                new Assert\NotBlank()
            ],
            'type' => [
                new Assert\NotBlank()
            ],
            'content' => [
                new Assert\NotBlank()
            ],
            'ttl' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ],
            'prio' => [
                new Assert\Type('numeric')
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_POST)) {
            $this->showFirstValidationError($_POST);
            return;
        }

        $template = new ZoneTemplate($this->db, $this->getConfig());

        if ($template->editZoneTemplRecord($_POST)) {
            $this->setMessage('edit_zone_templ', 'success', _('Zone template has been updated successfully.'));
            $this->redirect('index.php', ['page' => 'edit_zone_templ', 'id' => $zone_templ_id]);
        }
    }
}
