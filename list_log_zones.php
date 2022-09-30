<?php
/**
 * Script that displays list of event logs
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\AppFactory;
use Poweradmin\DbZoneLogger;

require_once 'inc/toolkit.inc.php';
require_once 'inc/pagination.inc.php';
include_once 'inc/header.inc.php';

$app = AppFactory::create();

if (!do_hook('verify_permission', 'user_is_ueberuser')) {
    die("You do not have the permission to see any logs");
}

$selected_page = 1;
if (isset($_GET['start'])) {
    is_numeric($_GET['start']) ? $selected_page = $_GET['start'] : die("Unknown page.");
    if ($selected_page < 0) die('Unknown page.');
}

$number_of_logs = 0;
$logs_per_page = $app->config('iface_rowamount');
$logs = null;

if (isset($_GET['name']) && $_GET['name'] != '') {
    $number_of_logs = DbZoneLogger::count_logs_by_domain($_GET['name']);
    $number_of_pages = ceil($number_of_logs / $logs_per_page);
    if ($number_of_logs != 0 && $selected_page > $number_of_pages) die('Unknown page');
    $logs = DbZoneLogger::get_logs_for_domain($_GET['name'], $logs_per_page, ($selected_page - 1) * $logs_per_page);

} else {
    $number_of_logs = DbZoneLogger::count_all_logs();
    $number_of_pages = ceil($number_of_logs / $logs_per_page);
    if ($number_of_logs != 0 && $selected_page > $number_of_pages) die('Unknown page');
    $logs = DbZoneLogger::get_all_logs($logs_per_page, ($selected_page - 1) * $logs_per_page);
}

$app->render('list_log_zones.html', [
    'number_of_logs' => $number_of_logs,
    'name' => isset($_GET['name']) ? htmlspecialchars($_GET['name']) : null,
    'data' => $logs,
    'selected_page' => $selected_page,
    'logs_per_page' => $logs_per_page,
    'pagination' => show_pages($number_of_logs, $logs_per_page),
]);

include_once('inc/footer.inc.php');

