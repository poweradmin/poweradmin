<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2014  Poweradmin Development Team
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
 * Script that handles search requests
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once('inc/toolkit.inc.php');
include_once('inc/header.inc.php');

if (!(verify_permission('search'))) {
    error(ERR_PERM_SEARCH);
    include_once('inc/footer.inc.php');
    exit;
} else {
    echo "     <h2>" . _('Search zones and records') . "</h2>\n";
    $holy_grail = '';
    if (isset($_POST['query'])) {

        if (verify_permission('zone_content_view_others')) {
            $perm_view = "all";
        } elseif (verify_permission('zone_content_view_own')) {
            $perm_view = "own";
        } else {
            $perm_view = "none";
        }

        if (verify_permission('zone_content_edit_others')) {
            $perm_edit = "all";
        } elseif (verify_permission('zone_content_edit_own')) {
            $perm_edit = "own";
        } else {
            $perm_edit = "none";
        }

        $holy_grail = $_POST['query'];
        $wildcards = ($_POST['wildcards'] == "true" ? true : false);
        $arpa = ($_POST['arpa'] == "true" ? true : false);

        $result = search_zone_and_record($holy_grail, $perm_view, ZONE_SORT_BY, RECORD_SORT_BY, $wildcards, $arpa);

        if (is_array($result['zones'])) {
            echo "     <script language=\"JavaScript\" type=\"text/javascript\">\n";
            echo "     <!--\n";
            echo "     function zone_sort_by ( sortbytype )\n";
            echo "     {\n";
            echo "       document.sortby_zone_form.zone_sort_by.value = sortbytype ;\n";
            echo "       document.sortby_zone_form.submit() ;\n";
            echo "     }\n";
            echo "     -->\n";
            echo "     </script>\n";
            echo "     <form name=\"sortby_zone_form\" method=\"post\" action=\"search.php\">\n";
            echo "     <input type=\"hidden\" name=\"query\" value=\"" . $_POST['query'] . "\" />\n";
            echo "     <input type=\"hidden\" name=\"zone_sort_by\" />\n";
            echo "     <h3>" . _('Zones found') . ":</h3>\n";
            echo "     <table>\n";
            echo "      <tr>\n";
            echo "       <th>&nbsp;</th>\n";
            echo "       <th><a href=\"javascript:zone_sort_by('name')\">" . _('Name') . "</a></th>\n";
            echo "       <th><a href=\"javascript:zone_sort_by('type')\">" . _('Type') . "</a></th>\n";
            echo "       <th><a href=\"javascript:zone_sort_by('master')\">" . _('Master') . "</a></th>\n";
            /* If user has all edit permissions show zone owners */
            if ($perm_edit == "all") {
                echo "	     <th><a href=\"javascript:zone_sort_by('owner')\">" . _('Owner') . "</a></th>\n";
            }

            echo "      </tr>\n";
            echo "      </form>\n";

            foreach ($result['zones'] as $zone) {
                echo "      <tr>\n";
                echo "          <td>\n";
                echo "           <a href=\"edit.php?name=" . $zone['name'] . "&id=" . $zone['zid'] . "\"><img src=\"images/edit.gif\" title=\"" . _('Edit zone') . " " . $zone['name'] . "\" alt=\"[ " . _('Edit zone') . " " . $zone['name'] . " ]\"></a>\n";
                if ($perm_edit != "all" || $perm_edit != "none") {
                    $user_is_zone_owner = verify_user_is_owner_zoneid($zone['zid']);
                }
                if ($perm_edit == "all" || ( $perm_edit == "own" && $user_is_zone_owner == "1")) {
                    echo "           <a href=\"delete_domain.php?name=" . $zone['name'] . "&id=" . $zone['zid'] . "\"><img src=\"images/delete.gif\" title=\"" . _('Delete zone') . " " . $zone['name'] . "\" alt=\"[ " . _('Delete zone') . " " . $zone['name'] . " ]\"></a>\n";
                }
                echo "          </td>\n";
                echo "       <td>" . $zone['name'] . "</td>\n";
                echo "       <td>" . $zone['type'] . "</td>\n";
                if ($zone['type'] == "SLAVE") {
                    echo "       <td>" . $zone['master'] . "</td>\n";
                } else {
                    echo "       <td>&nbsp;</td>\n";
                }
                if ($perm_edit == "all") {
                    echo "          <td>" . $zone['owner'] . "</td>";
                }
                echo "      </tr>\n";
            }
            echo "     </table>\n";
        }

        if (is_array($result['records'])) {
            echo "     <script language=\"JavaScript\" type=\"text/javascript\">\n";
            echo "     <!--\n";
            echo "     function record_sort_by ( sortbytype )\n";
            echo "     {\n";
            echo "       document.sortby_record_form.record_sort_by.value = sortbytype ;\n";
            echo "       document.sortby_record_form.submit() ;\n";
            echo "     }\n";
            echo "     -->\n";
            echo "     </script>\n";
            echo "     <form name=\"sortby_record_form\" method=\"post\" action=\"search.php\">\n";
            echo "     <input type=\"hidden\" name=\"query\" value=\"" . $_POST['query'] . "\" />\n";
            echo "     <input type=\"hidden\" name=\"record_sort_by\" />\n";
            echo "     <h3>" . _('Records found') . ":</h3>\n";
            echo "     <table>\n";
            echo "      <tr>\n";
            echo "       <th>&nbsp;</th>\n";
            echo "       <th><a href=\"javascript:record_sort_by('name')\">" . _('Name') . "</a></th>\n";
            echo "       <th><a href=\"javascript:record_sort_by('type')\">" . _('Type') . "</a></th>\n";
            echo "       <th><a href=\"javascript:record_sort_by('content')\">" . _('Content') . "</a></th>\n";
            echo "       <th>Priority</th>\n";
            echo "       <th><a href=\"javascript:record_sort_by('ttl')\">" . _('TTL') . "</a></th>\n";
            echo "      </tr>\n";
            echo "      </form>\n";

            foreach ($result['records'] as $record) {

                echo "      <tr>\n";
                echo "          <td>\n";
                echo "           <a href=\"edit_record.php?id=" . $record['rid'] . "\"><img src=\"images/edit.gif\" title=\"" . _('Edit record') . " " . $record['name'] . "\" alt=\"[ " . _('Edit record') . " " . $record['name'] . " ]\"></a>\n";
                if ($perm_edit != "all" || $perm_edit != "none") {
                    $user_is_zone_owner = verify_user_is_owner_zoneid($record['zid']);
                }
                if ($perm_edit == "all" || ( $perm_edit == "own" && $user_is_zone_owner == "1")) {
                    echo "           <a href=\"delete_record.php?id=" . $record['rid'] . "\"><img src=\"images/delete.gif\" title=\"" . _('Delete record') . " " . $record['name'] . "\" alt=\"[ " . _('Delete record') . " " . $record['name'] . " ]\"></a>\n";
                }
                echo "          </td>\n";
                echo "       <td>" . $record['name'] . "</td>\n";
                echo "       <td>" . $record['type'] . "</td>\n";
                echo "       <td>" . $record['content'] . "</td>\n";
                if ($record['type'] == "MX" || $record['type'] == "SRV") {
                    echo "       <td>" . $record['prio'] . "</td>\n";
                } else {
                    echo "       <td>&nbsp;</td>\n";
                }
                echo "       <td>" . $record['ttl'] . "</td>\n";
                echo "      </tr>\n";
            }
            echo "     </table>\n";
        }
    } else { // !isset($_POST['query'])
        $wildcards = true;
        $arpa = true;
    }

    echo "     <h3>" . _('Query') . ":</h3>\n";
    echo "      <form method=\"post\" action=\"" . htmlentities($_SERVER['PHP_SELF'], ENT_QUOTES) . "\">\n";
    echo "       <table>\n";
    echo "        <tr>\n";
    echo "         <td>\n";
    echo "          <input type=\"text\" class=\"input\" name=\"query\" value=\"" . $holy_grail . "\">&nbsp;\n";
    echo "          <input type=\"submit\" class=\"button\" name=\"submit\" value=\"" . _('Search') . "\">\n";
    echo "          <input type=\"checkbox\" class=\"input\" name=\"wildcards\" value=\"true\"" . ($wildcards ? "checked=\"checked\"" : "") . ">" . _('Wildcard') . "\n";
    echo "          <input type=\"checkbox\" class=\"input\" name=\"arpa\" value=\"true\"" . ($arpa ? "checked=\"checked\"" : "") . ">" . _('Reverse') . "\n";
    echo "         </td>\n";
    echo "        </tr>\n";
    echo "        <tr>\n";
    echo "         <td>\n";
    echo "          " . _('Enter a hostname or IP address. SQL LIKE syntax supported: an underscore (_) in pattern matches any single character, a percent sign (%) matches any string of zero or more characters.') . "\n";
    echo "         </td>\n";
    echo "        </tr>\n";
    echo "       </table>\n";
    echo "      </form>\n";
}
include_once('inc/footer.inc.php');
