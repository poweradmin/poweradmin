<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
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

namespace Poweradmin;

class DbUserLogger
{
    public static function do_log($msg, $priority)
    {
        global $db;

        $stmt = $db->prepare('INSERT INTO log_users (event, priority) VALUES (:msg, :priority)');
        $stmt->execute([
            ':msg' => $msg,
            ':priority' => $priority,
        ]);
    }

    public static function count_all_logs()
    {
        global $db;
        $stmt = $db->query("SELECT count(*) AS number_of_logs FROM log_users");
        return $stmt->fetch()['number_of_logs'];
    }


    public static function count_logs_by_user($user)
    {
        global $db;
        $stmt = $db->prepare("
                    SELECT count(log_users.id) as number_of_logs
                    FROM log_users
                    WHERE log_users.event LIKE :search_by
        ");
        $name = "%'$user'%";
        $stmt->execute(['search_by' => $name]);
        return $stmt->fetch()['number_of_logs'];
    }

    public static function get_all_logs($limit, $offset)
    {
        global $db;
        $stmt = $db->prepare("
                    SELECT * FROM log_users
                    ORDER BY created_at DESC 
                    LIMIT :limit 
                    OFFSET :offset 
        ");

        $stmt->execute([
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $stmt->fetchAll();
    }

    public static function get_logs_for_user($user, $limit, $offset)
    {
        if (!(User::exists($user))) {
            return array();
        }

        global $db;
        $stmt = $db->prepare("
            SELECT * FROM log_users
            WHERE log_users.event LIKE :search_by
            LIMIT :limit 
            OFFSET :offset"
        );

        $user = "%'$user'%";
        $stmt->execute([
            'search_by' => $user,
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $stmt->fetchAll();
    }
}