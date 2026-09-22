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

namespace Poweradmin\Application\Boot;

/**
 * How an entry point wants the process booted; see Kernel::boot().
 */
enum BootOptions
{
    /** index.php: a session unless the install is headless or a probe is asking; the database on first use */
    case Web;

    /** dynamic_update.php and bin/poweradmin: no session, the database opened up front */
    case Script;

    /** install/index.php: a session for the wizard, no configuration file and no database yet */
    case Installer;

    public function startsSession(): bool
    {
        return $this !== self::Script;
    }

    /**
     * Neither a headless install nor the monitoring probes have any use for a
     * session, and starting one per scrape would leave a session file behind.
     */
    public function skipsSessionWhenHeadless(): bool
    {
        return $this === self::Web;
    }

    public function connectsDatabase(): bool
    {
        return $this === self::Script;
    }

    /**
     * Only the web interface refuses to start on a broken configuration; the
     * scripts report what fails as it fails, and the installer has none yet.
     */
    public function guardsConfiguration(): bool
    {
        return $this === self::Web;
    }
}
