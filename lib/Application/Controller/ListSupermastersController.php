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
 * Script that displays supermasters list
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;

class ListSupermastersController extends BaseController
{

    public function run(): void
    {
        $this->checkPermission('supermaster_view', _("You do not have the permission to view supermasters."));

        $this->showSuperMasters();
    }

    private function showSuperMasters(): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $this->render('list_supermasters.html', [
            'perm_sm_add' => UserManager::verify_permission($this->db, 'supermaster_add'),
            'perm_sm_edit' => UserManager::verify_permission($this->db, 'supermaster_edit'),
            'supermasters' => $dnsRecord->get_supermasters()
        ]);
    }
}
