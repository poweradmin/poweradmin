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

function logout(string $msg = "", string $type = ""): void
{
    session_regenerate_id(true);
    session_unset();
    session_write_close();
    auth($msg, $type);
    exit;
}

function auth(string $msg = "", string $type = "success"): void
{
    $args['time'] = time();
    $url = htmlentities('login.php', ENT_QUOTES) . "?" . http_build_query($args);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['message'] = $msg;
    $_SESSION['type'] = $type;

    header("Location: $url");
    exit;
}
