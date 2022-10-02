<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
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

/**
 * Script that handles search requests
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\DnsRecord;

require_once 'inc/toolkit.inc.php';
require_once 'inc/header.inc.php';

if (!do_hook('verify_permission', 'search')) {
    error(ERR_PERM_SEARCH);
    require_once 'inc/footer.inc.php';
    exit;
}

if (isset($_GET["zone_sort_by"]) && preg_match("/^[a-z_]+$/", $_GET["zone_sort_by"])) {
    define('ZONE_SORT_BY', $_GET["zone_sort_by"]);
    $_SESSION["search_zone_sort_by"] = $_GET["zone_sort_by"];
} elseif (isset($_POST["zone_sort_by"]) && preg_match("/^[a-z_]+$/", $_POST["zone_sort_by"])) {
    define('ZONE_SORT_BY', $_POST["zone_sort_by"]);
    $_SESSION["search_zone_sort_by"] = $_POST["zone_sort_by"];
} elseif (isset($_SESSION["search_zone_sort_by"])) {
    define('ZONE_SORT_BY', $_SESSION["search_zone_sort_by"]);
} else {
    define('ZONE_SORT_BY', "name");
}

if (isset($_GET["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_GET["record_sort_by"])) {
    define('RECORD_SORT_BY', $_GET["record_sort_by"]);
    $_SESSION["record_sort_by"] = $_GET["record_sort_by"];
} elseif (isset($_POST["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_POST["record_sort_by"])) {
    define('RECORD_SORT_BY', $_POST["record_sort_by"]);
    $_SESSION["record_sort_by"] = $_POST["record_sort_by"];
} elseif (isset($_SESSION["record_sort_by"])) {
    define('RECORD_SORT_BY', $_SESSION["record_sort_by"]);
} else {
    define('RECORD_SORT_BY', "name");
}

$parameters['query'] = isset($_POST['query']) && !empty($_POST['query']) ? $_POST['query'] : '';
$parameters['zones'] = !isset($_POST['do_search']) && !isset($_POST['zones']) || isset($_POST['zones']) && $_POST['zones'] == true ? true : false;
$parameters['records'] = !isset($_POST['do_search']) && !isset($_POST['records']) || isset($_POST['records']) && $_POST['records'] == true ? true : false;
$parameters['wildcard'] = !isset($_POST['do_search']) && !isset($_POST['wildcard']) || isset($_POST['wildcard']) && $_POST['wildcard'] == true ? true : false;
$parameters['reverse'] = !isset($_POST['do_search']) && !isset($_POST['reverse']) || isset($_POST['reverse']) && $_POST['reverse'] == true ? true : false;

?>

<h5 class="mb-3"><?php echo _('Search zones and records'); ?></h5>
<span class="text-secondary"><?php echo _('Enter a hostname or IP address. SQL LIKE syntax supported: an underscore (_) in pattern matches any single character, a percent sign (%) matches any string of zero or more characters.'); ?></span>
<form name="search_form" action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST">
    <input type="hidden" name="zone_sort_by" value="<?php echo ZONE_SORT_BY; ?>">
    <input type="hidden" name="record_sort_by" value="<?php echo RECORD_SORT_BY; ?>">

    <div class="row pt-3 pb-3">
        <div class="col-sm-4">
            <div class="input-group">
                <input type="text" class="form-control form-control-sm" name="query" value="<?php echo $parameters['query']; ?>">
                <input type="submit" class="btn btn-primary btn-sm" name="do_search" value="<?php echo _('Search'); ?>">
            </div>
        </div>
        <div class="col-sm-8 d-flex align-items-center">
            <div class="form-check form-check-inline">
                <input type="checkbox" class="form-check-input" name="zones" id="inlineCheckbox1"
                       value="true"<?php echo $parameters['zones'] ? ' checked="checked"' : ''; ?>>
                <label class="form-check-label" for="inlineCheckbox1"><?php echo _('Zones'); ?></label>
            </div>
            <div class="form-check form-check-inline">
                <input type="checkbox" class="form-check-input" name="records" id="inlineCheckbox2"
                       value="true"<?php echo $parameters['records'] ? ' checked="checked"' : ''; ?>>
                <label class="form-check-label" for="inlineCheckbox2"><?php echo _('Records'); ?></label>
            </div>
            <div class="form-check form-check-inline">
                <input type="checkbox" class="form-check-input" name="wildcard" id="inlineCheckbox3"
                       value="true"<?php echo $parameters['wildcard'] ? ' checked="checked"' : ''; ?>>
                <label class="form-check-label" for="inlineCheckbox3"><?php echo _('Wildcard'); ?></label>
            </div>
            <div class="form-check form-check-inline">
                <input type="checkbox" class="form-check-input" name="reverse" id="inlineCheckbox4"
                       value="true"<?php echo $parameters['reverse'] ? ' checked="checked"' : ''; ?>>
                <label class="form-check-label" for="inlineCheckbox4"><?php echo _('Reverse'); ?></label>
            </div>
        </div>
    </div>
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

    $searchResult = DnsRecord::search_zone_and_record(
        $parameters,
        $permissions['view'],
        ZONE_SORT_BY,
        RECORD_SORT_BY
    );

    if (is_array($searchResult['zones'])):
        ?>
        <div class="pb-3">
            <h5 class="mb-3 pt-3 border-top"><?php echo _('Zones found'); ?></h5>
            <table class="table table-striped table-hover table-sm">
                <thead>
                <tr>
                    <th><a href="javascript:zone_sort_by('name');"><?php echo _('Name'); ?></a></th>
                    <th><a href="javascript:zone_sort_by('type');"><?php echo _('Type'); ?></a></th>
                    <th><a href="javascript:zone_sort_by('count_records');"><?php echo _('Records'); ?></a></th>
                    <th><a href="javascript:zone_sort_by('fullname');"><?php echo _('Owner'); ?></a></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$searchResult['zones']): ?>
                    <tr>
                        <td colspan="5"><?php echo _('No results found'); ?></td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($searchResult['zones'] as $zone): ?>
                    <tr>
                        <td><?php echo $zone['name']; ?></td>
                        <td><?php echo $zone['type']; ?></td>
                        <td><?php echo $zone['count_records']; ?></td>
                        <td><?php echo $zone['fullname']; ?></td>
                        <td>
                            <?php if ($permissions['edit'] == 'all' || $permissions['edit'] == 'own' && do_hook('verify_user_is_owner_zoneid', $zone['id'])): ?>
                                <a class="btn btn-outline-primary btn-sm"
                                   href="<?php echo 'edit.php?name=' . $zone['name'] . '&id=' . $zone['id']; ?>">
                                    <i class="bi bi-pencil-square"></i> <?php echo _('Edit zone'); ?></a>
                                <a class="btn btn-outline-danger btn-sm"
                                   href="<?php echo 'delete_domain.php?name=' . $zone['name'] . '&id=' . $zone['id']; ?>">
                                    <i class="bi bi-trash"></i> <?php echo _('Delete zone'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php
    endif;
    if (is_array($searchResult['records'])):
        ?>

        <div class="pb-3">
            <h5 class="mb-3 pt-3 border-top"><?php echo _('Records found'); ?></h5>
            <table class="table table-striped table-hover table-sm">
                <thead>
                <tr>
                    <th><a href="javascript:record_sort_by('name');"><?php echo _('Name'); ?></a></th>
                    <th><a href="javascript:record_sort_by('type');"><?php echo _('Type'); ?></a></th>
                    <th><a href="javascript:record_sort_by('prio');"><?php echo _('Priority'); ?></a></th>
                    <th><a href="javascript:record_sort_by('content');"><?php echo _('Content'); ?></a></th>
                    <th><a href="javascript:record_sort_by('ttl');"><?php echo _('TTL'); ?></a></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$searchResult['zones']): ?>
                    <tr>
                        <td colspan="6"><?php echo _('No results found'); ?></td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($searchResult['records'] as $record): ?>
                    <tr>
                        <td><?php echo $record['name']; ?></td>
                        <td><?php echo $record['type']; ?></td>
                        <td><?php echo $record['prio']; ?></td>
                        <td><?php echo $record['content']; ?></td>
                        <td><?php echo $record['ttl']; ?></td>
                        <td>
                            <?php if ($permissions['edit'] == 'all' || $permissions['edit'] == 'own' && do_hook('verify_user_is_owner_zoneid', $record['domain_id'])): ?>
                                <a class="btn btn-outline-primary btn-sm"
                                   href="<?php echo 'edit_record.php?domain=' . $record['domain_id'] . '&id=' . $record['id']; ?>">
                                    <i class="bi bi-pencil-square"></i> <?php echo _('Edit zone'); ?></a>
                                <a class="btn btn-outline-danger btn-sm"
                                   href="<?php echo 'delete_record.php?domain=' . $record['domain_id'] . '&id=' . $record['id']; ?>">
                                    <i class="bi bi-trash"></i> <?php echo _('Delete zone'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php
    endif;
}

require_once 'inc/footer.inc.php';

?>
