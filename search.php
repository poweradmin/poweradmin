<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */

require_once 'inc/toolkit.inc.php';
require_once 'inc/header.inc.php';

if (!do_hook('verify_permission', 'search')) {
    error(ERR_PERM_SEARCH);
    require_once 'inc/footer.inc.php';
    die();
}

$parameters['query'] = isset($_POST['query']) && !empty($_POST['query']) ? idn_to_ascii($_POST['query']) : '';
$parameters['zones'] = !isset($_POST['do_search']) && !isset($_POST['zones']) || isset($_POST['zones']) && $_POST['zones'] == true ? true : false;
$parameters['records'] = !isset($_POST['do_search']) && !isset($_POST['records']) || isset($_POST['records']) && $_POST['records'] == true ? true : false;
$parameters['wildcard'] = !isset($_POST['do_search']) && !isset($_POST['wildcard']) || isset($_POST['wildcard']) && $_POST['wildcard'] == true ? true : false;
$parameters['reverse'] = !isset($_POST['do_search']) && !isset($_POST['reverse']) || isset($_POST['reverse']) && $_POST['reverse'] == true ? true : false;

?>

<h2><?php echo _('Search zones and records'); ?></h2>
<form name="search_form" action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST">
    <table>
        <tr>
            <td>
                <input type="text" class="input" name="query" value="<?php echo $parameters['query']; ?>">
                <input type="submit" class="button" name="do_search" value="<?php echo _('Search'); ?>">
                <input type="checkbox" class="input" name="zones" value="true"<?php echo $parameters['zones'] ? ' checked="checked"' : ''; ?>><?php echo _('Zones'); ?>
                <input type="checkbox" class="input" name="records" value="true"<?php echo $parameters['records'] ? ' checked="checked"' : ''; ?>><?php echo _('Records'); ?> |
                <input type="checkbox" class="input" name="wildcard" value="true"<?php echo $parameters['wildcard'] ? ' checked="checked"' : ''; ?>><?php echo _('Wildcard'); ?>
                <input type="checkbox" class="input" name="reverse" value="true"<?php echo $parameters['reverse'] ? ' checked="checked"' : ''; ?>><?php echo _('Reverse'); ?>
                <input type="hidden" name="zone_sort_by" value="<?php echo ZONE_SORT_BY; ?>">
                <input type="hidden" name="record_sort_by" value="<?php echo RECORD_SORT_BY; ?>">
            </td>
        </tr>
        <tr>
            <td><?php echo _('Enter a hostname or IP address. SQL LIKE syntax supported: an underscore (_) in pattern matches any single character, a percent sign (%) matches any string of zero or more characters.'); ?></td>
        </tr>
    </table>
</form>

<?php

if (isset($_POST['query'])) {
    if (do_hook('verify_permission', 'zone_content_view_others')) {
        $permissions['view'] = "all";
    } elseif (do_hook('verify_permission', 'zone_content_view_own')) {
        $permissions['view'] = "own";
    } else {
        $permissions['view'] = "none";
    }

    if (do_hook('verify_permission', 'zone_content_edit_others')) {
        $permissions['edit'] = "all";
    } elseif (do_hook('verify_permission', 'zone_content_edit_own')) {
        $permissions['edit'] = "own";
    } else {
        $permissions['edit'] = "none";
    }

    $searchResult = search_zone_and_record(
        $parameters,
        $permissions['view'],
        ZONE_SORT_BY,
        RECORD_SORT_BY
    );

    if (is_array($searchResult['zones'])):
?>

        <h3><?php echo _('Zones found'); ?></h3>
        <table>
            <tr>
                <th></th>
                <th><a href="javascript:zone_sort_by('name');"><?php echo _('Name'); ?></a></th>
                <th><a href="javascript:zone_sort_by('type');"><?php echo _('Type'); ?></a></th>
                <th><a href="javascript:zone_sort_by('count_records');"><?php echo _('Records'); ?></a></th>
                <th><a href="javascript:zone_sort_by('fullname');"><?php echo _('Owner'); ?></a></th>
            </tr>
            <?php foreach ($searchResult['zones'] as $zone): ?>
                <tr>
                    <td>
                        <?php if ($permissions['edit'] == 'all' || $permissions['edit'] == 'own' && do_hook('verify_user_is_owner_zoneid', $zone['id'])): ?>
                            <a href="<?php echo 'edit.php?name=' . $zone['name'] . '&id=' . $zone['id']; ?>"><img src="images/edit.gif" alt="[ <?php echo _('Edit zone'); ?> ]" title="<?php echo _('Edit zone'); ?>"></a>
                            <a href="<?php echo 'delete_domain.php?name=' . $zone['name'] . '&id=' . $zone['id']; ?>"><img src="images/delete.gif" alt="[ <?php echo _('Delete zone'); ?> ]" title="<?php echo _('Delete zone'); ?>"></a>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $zone['name']; ?></td>
                    <td><?php echo $zone['type']; ?></td>
                    <td><?php echo $zone['count_records']; ?></td>
                    <td><?php echo $zone['fullname']; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

<?php
    endif;
    if (is_array($searchResult['records'])):
?>

        <h3><?php echo _('Records found'); ?></h3>
        <table>
            <tr>
                <th></th>
                <th><a href="javascript:record_sort_by('name');"><?php echo _('Name'); ?></a></th>
                <th><a href="javascript:record_sort_by('type');"><?php echo _('Type'); ?></a></th>
                <th><a href="javascript:record_sort_by('prio');"><?php echo _('Priority'); ?></a></th>
                <th><a href="javascript:record_sort_by('content');"><?php echo _('Content'); ?></a></th>
                <th><a href="javascript:record_sort_by('ttl');"><?php echo _('TTL'); ?></a></th>
            </tr>
            <?php foreach ($searchResult['records'] as $record): ?>
                <tr>
                    <td>
                        <?php if ($permissions['edit'] == 'all' || $permissions['edit'] == 'own' && do_hook('verify_user_is_owner_zoneid', $record['domain_id'])): ?>
                            <a href="<?php echo 'edit_record.php?domain=' . $record['domain_id'] . '&id=' . $record['id']; ?>"><img src="images/edit.gif" alt="[ <?php echo _('Edit zone'); ?> ]" title="<?php echo _('Edit zone'); ?>"></a>
                            <a href="<?php echo 'delete_record.php?domain=' . $record['domain_id'] . '&id=' . $record['id']; ?>"><img src="images/delete.gif" alt="[ <?php echo _('Delete zone'); ?> ]" title="<?php echo _('Delete zone'); ?>"></a>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $record['name']; ?></td>
                    <td><?php echo $record['type']; ?></td>
                    <td><?php echo $record['prio']; ?></td>
                    <td><?php echo $record['content']; ?></td>
                    <td><?php echo $record['ttl']; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <script type="text/javascript">
            function zone_sort_by(sortbytype)
            {
                document.search_form.zone_sort_by.value = sortbytype;
                document.getElementsByName("do_search")[0].click();
            }

            function record_sort_by(sortbytype)
            {
                document.search_form.record_sort_by.value = sortbytype;
                document.getElementsByName("do_search")[0].click();
            }
        </script>

<?php
    endif;
}

require_once 'inc/footer.inc.php';

?>
