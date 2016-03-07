<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2015  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
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
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Script that displays logs of resource and domain changes.
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2015 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");
include_once("inc/ChangeLogger.class.php");
include_once("inc/TimeHelper.class.php");


echo "<h2>" . _('Show logs') . "</h2>";

if(!$perm_is_godlike) {
    echo "<p>" . _('You do not have the permission to see the logs.') . "</p>";
    include_once("inc/footer.inc.php");
    exit;
}

$intervals = array(
    "P1M" => _("One month ago"),
    "P1W" => _("One week ago"),
    "P1D" => _("One day ago"),
    "PT6H" => _("6 hours ago"),
    "PT1H" => _("An hour ago"),
);
$th = new TimeHelper();

// Show the horizontal timespan-selection menu
echo '<p class="hnav"> <a class="hnav-item-first" href="list_log.php">' . _("All") . '</a>';
foreach($intervals as $interval => $text) {
    $timestamp = $th->now_minus($interval)->format($th->format);
    echo ' | <a class="hnav-item" href="list_log.php?changes_since=' . $timestamp . '">' . $text . "</a>";
}
echo "</p>";

// Show the diff
global $db;
$log_out = ChangeLogger::with_db($db);
if (isset($_GET["changes_since"]) && preg_match("/^" . $th->regex() . "$/", $_GET["changes_since"])) {
    // Show only logs since …
    $changes_since = $_GET["changes_since"];
    echo $log_out->html_diff($changes_since);
} else {
    // Show all logs
    echo $log_out->html_diff();
}

include_once("inc/footer.inc.php");
