<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Application\Controller\Template;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Domain\Model\Permission;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Handles the POST that marks a zone template as the default or clears the default flag.
 */
class SetDefaultZoneTemplController extends BaseController
{
    public function run(): void
    {
        $perm_godlike = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);
        $this->checkCondition(!$perm_godlike, _("You do not have the permission to change the default zone template."));

        $action = $this->getSafeRequestValue('action');
        $zoneTemplate = $this->services()->zoneTemplateWriteService();

        if ($action === 'unset') {
            $cleared = $zoneTemplate->unsetDefaultTemplate();
            if (!$cleared->success) {
                $this->setMessage('list_zone_templ', 'error', (string)$cleared->message);
            } else {
                // Warn when config fallback is still active so the message
                // reflects the effective state, not just the DB write.
                $remaining = $zoneTemplate->getDefaultTemplateId();
                if ($remaining !== null) {
                    $this->setMessage(
                        'list_zone_templ',
                        'warning',
                        _('Default flag cleared, but the dns.default_zone_template config setting is still active.')
                    );
                } else {
                    $this->setMessage('list_zone_templ', 'success', _('Default zone template cleared.'));
                }
            }
            $this->redirect('/zones/templates');
            return;
        }

        $constraints = [
            'id' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
            ],
        ];
        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($this->requestData)) {
            $this->showFirstValidationError($this->requestData);
            return;
        }

        $zone_templ_id = (int) $this->getSafeRequestValue('id');
        $flagged = $zoneTemplate->setDefaultTemplate($zone_templ_id);
        if ($flagged->success) {
            $this->setMessage('list_zone_templ', 'success', _('Default zone template updated.'));
        } else {
            $this->setMessage('list_zone_templ', 'error', (string)$flagged->message);
        }
        $this->redirect('/zones/templates');
    }
}
