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

/**
 *  Poweradmin installer
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

// Dependencies
use Poweradmin\Application\Services\UserAuthenticationService;
use Poweradmin\Configuration;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\PoFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

require_once dirname(__DIR__) . '/inc/messages.inc.php';
require_once dirname(__DIR__) . '/inc/i18n.inc.php';

// Constants
$local_config_file = dirname(__DIR__) . '/inc/config.inc.php';
const SESSION_KEY_LENGTH = 46;

// Localize interface
$language = 'en_EN';
if (isset($_POST['language']) && $_POST['language'] != 'en_EN') {
    $language = $_POST['language'];

    $locale = setlocale(LC_ALL, $language, $language . '.UTF-8');
    if (!$locale) {
        error(_('Failed to set locale. Selected locale may be unsupported on this system. Please contact your administrator.'));
    }

    $gettext_domain = 'messages';
    bindtextdomain($gettext_domain, "./../locale");
    textdomain($gettext_domain);
    @putenv('LANG=' . $language);
    @putenv('LANGUAGE=' . $language);
}

// Initialize Twig template engine
$loader = new FilesystemLoader('templates');
$twig = new Environment($loader);
$translator = new Translator($language);
$translator->addLoader('po', new PoFileLoader());
$translator->addResource('po', getLocaleFile($language), $language);
$twig->addExtension(new TranslationExtension($translator));

// Display header
$current_step = isset($_POST['step']) && is_numeric($_POST['step']) ? $_POST['step'] : 1;
echo $twig->render('header.html', array('current_step' => htmlspecialchars($current_step), 'file_version' => time()));

if ($current_step == 1 && file_exists('../inc/config.inc.php')) {
    echo "<p class='alert alert-danger'>" . _('There is already a configuration file in place, so the installation will be skipped.') . "</p>";
    echo $twig->render('footer.html');
    exit;
}

$config = new Configuration('../inc/config-me.inc.php');

switch ($current_step) {

    case 1:
        echo $twig->render('step1.html', array('next_step' => ++$current_step));
        break;

    case 2:
        echo $twig->render('step2.html', array('next_step' => ++$current_step, 'language' => htmlspecialchars($language)));
        break;

    case 3:
        echo $twig->render('step3.html', array('next_step' => ++$current_step, 'language' => htmlspecialchars($language)));
        break;

    case 4:
        echo "<p class='alert alert-secondary'>" . _('Updating database...') . " ";
        include_once("../inc/config-me.inc.php");
        $db_user = $_POST['user'];
        $db_pass = $_POST['pass'];
        $db_host = $_POST['host'];
        $db_port = $_POST['dbport'];
        $db_name = $_POST['name'];
        $db_charset = $_POST['charset'];
        $db_collation = $_POST['collation'];
        $db_type = $_POST['type'];
        if ($db_type == 'sqlite') {
            $db_file = $db_name;
        }
        $pa_pass = $_POST['pa_pass'];
        require_once("../inc/database.inc.php");
        $db = dbConnect($isQuiet = false);
        include_once("database-structure.inc.php");
        $current_tables = $db->listTables();

        foreach ($def_tables as $table) {
            if (in_array($table['table_name'], $current_tables)) {
                $db->dropTable($table['table_name']);
            }

            $options = $table['options'];

            if ($db_charset) {
                $options['charset'] = $db_charset;
            }

            if ($db_collation) {
                $options['collation'] = $db_collation;
            }
            $db->createTable($table['table_name'], $table['fields'], $options);
        }

        $fill_perm_items = $db->prepare('INSERT INTO perm_items VALUES (?, ?, ?)');
        $def_permissions = include 'includes/permissions.php';
        $db->executeMultiple($fill_perm_items, $def_permissions);
        if (method_exists($fill_perm_items, 'free')) {
            $fill_perm_items->free();
        }

        // Create an administrator user with the appropriate permissions
        $adminName = 'Administrator';
        $adminDescr = 'Administrator template with full rights.';
        $stmt = $db->prepare("INSERT INTO perm_templ (name, descr) VALUES (:name, :descr)");
        $stmt->execute([':name' => $adminName, ':descr' => $adminDescr]);

        $permTemplId = $db->lastInsertId();

        $uberAdminUser = 'user_is_ueberuser';
        $stmt = $db->prepare("SELECT id FROM perm_items WHERE name = :name");
        $stmt->execute([':name' => $uberAdminUser]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $uberAdminUserId = $row['id'];

        $permTemplItemsQuery = $db->prepare("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (:perm_templ_id, :uber_admin_user_id)");
        $permTemplItemsQuery->execute([':perm_templ_id' => $permTemplId, ':uber_admin_user_id' => $uberAdminUserId]);

        $userAuthService = new UserAuthenticationService(
            $config->get('password_encryption'),
            $config->get('password_encryption_cost')
        );
        $user_query = $db->prepare("INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap) VALUES ('admin', ?, 'Administrator', 'admin@example.net', 'Administrator with full rights.', ?, 1, 0)");
        $user_query->execute(array($userAuthService->hashPassword($pa_pass), $permTemplId));

        echo _('done!') . "</p>";

        echo $twig->render('step4.html', array(
            'next_step' => (int)htmlspecialchars($current_step) + 1,
            'language' => htmlspecialchars($_POST['language']),
            'db_user' => htmlspecialchars($db_user),
            'db_pass' => htmlspecialchars($db_pass),
            'db_host' => htmlspecialchars($db_host),
            'db_port' => htmlspecialchars($db_port),
            'db_name' => htmlspecialchars($db_name),
            'db_type' => htmlspecialchars($db_type),
            'db_charset' => htmlspecialchars($db_charset),
            'pa_pass' => htmlspecialchars($pa_pass)
        ));
        break;

    case 5:
        $current_step++;
        $db_user = $_POST['db_user'];
        $db_pass = $_POST['db_pass'];
        $db_host = $_POST['db_host'];
        $db_port = $_POST['db_port'];
        $db_name = $_POST['db_name'];
        $db_type = $_POST['db_type'];
        $db_charset = $_POST['db_charset'];
        if ($db_type == 'sqlite') {
            $db_file = $db_name;
        } else {
            $pa_db_user = $_POST['pa_db_user'];
            $pa_db_pass = $_POST['pa_db_pass'];
        }
        $pa_pass = $_POST['pa_pass'];
        $hostmaster = $_POST['dns_hostmaster'];
        $dns_ns1 = $_POST['dns_ns1'];
        $dns_ns2 = $_POST['dns_ns2'];

        require_once("../inc/database.inc.php");
        $db = dbConnect($isQuiet = false);
        include_once("database-structure.inc.php");

        echo "<p>" . _('You now want to give limited rights to Poweradmin so it can update the data in the tables. To do this, you should create a new user and give it rights to select, delete, insert and update records in the PowerDNS database.') . " ";
        if ($db_type == 'mysql') {
            $pa_db_host = $db_host;

            $sql = 'SELECT USER()';
            $result = $db->queryRow($sql);
            if (isset($result['user()'])) {
                $current_db_user = $result['user()'];
                $pa_db_host = substr($current_db_user, strpos($current_db_user, '@') + 1);
            }

            echo _('In MySQL you should now perform the following command:') . "</p>";
            echo "<p><code>";
            echo "CREATE USER '" . htmlspecialchars($pa_db_user) . "'@'" . htmlspecialchars($pa_db_host) . "' IDENTIFIED BY '" . htmlspecialchars($pa_db_pass) . "';<br>";
            echo "GRANT SELECT, INSERT, UPDATE, DELETE<BR>ON " . htmlspecialchars($db_name) . ".*<br>TO '" . htmlspecialchars($pa_db_user) . "'@'" . htmlspecialchars($pa_db_host) . "';</code></p>";
        } elseif ($db_type == 'pgsql') {
            echo _('On PgSQL you would use:') . "</p>";
            echo "<p><code>$ createuser -E -P " . htmlspecialchars($pa_db_user) . "<br>" .
                "Enter password for new role: " . htmlspecialchars($pa_db_pass) . "<br>" .
                "Enter it again: " . htmlspecialchars($pa_db_pass) . "<br>" .
                "CREATE USER<br>" .
                "$ psql " . htmlspecialchars($db_name) . "<br>";
            echo "psql> ";
            foreach ($grantTables as $tableName) {
                echo "GRANT SELECT, INSERT, DELETE, UPDATE ON " . $tableName . " TO " . htmlspecialchars($pa_db_user) . ";<br />";
            }
            foreach ($grantSequences as $sequenceName) {
                echo "GRANT USAGE, SELECT ON SEQUENCE " . $sequenceName . " TO " . htmlspecialchars($pa_db_user) . ";<br />";
            }
            echo "</code></p>\n";
        }
        echo "<p>" . _('After you have added the new user, proceed with this installation procedure.') . "</p>\n";
        echo "<form method=\"post\">";
        echo "<input type=\"hidden\" name=\"db_host\" value=\"" . htmlspecialchars($db_host) . "\">";
        echo "<input type=\"hidden\" name=\"db_name\" value=\"" . htmlspecialchars($db_name) . "\">";
        echo "<input type=\"hidden\" name=\"db_port\" value=\"" . htmlspecialchars($db_port) . "\">";
        echo "<input type=\"hidden\" name=\"db_type\" value=\"" . htmlspecialchars($db_type) . "\">";
        echo "<input type=\"hidden\" name=\"db_user\" value=\"" . htmlspecialchars($db_user) . "\">";
        echo "<input type=\"hidden\" name=\"db_pass\" value=\"" . htmlspecialchars($db_pass) . "\">";
        echo "<input type=\"hidden\" name=\"db_charset\" value=\"" . htmlspecialchars($db_charset) . "\">";
        if ($db_type != 'sqlite') {
            echo "<input type=\"hidden\" name=\"pa_db_user\" value=\"" . htmlspecialchars($pa_db_user) . "\">";
            echo "<input type=\"hidden\" name=\"pa_db_pass\" value=\"" . htmlspecialchars($pa_db_pass) . "\">";
        }
        echo "<input type=\"hidden\" name=\"pa_pass\" value=\"" . htmlspecialchars($pa_pass) . "\">";
        echo "<input type=\"hidden\" name=\"dns_hostmaster\" value=\"" . htmlspecialchars($hostmaster) . "\">";
        echo "<input type=\"hidden\" name=\"dns_ns1\" value=\"" . htmlspecialchars($dns_ns1) . "\">";
        echo "<input type=\"hidden\" name=\"dns_ns2\" value=\"" . htmlspecialchars($dns_ns2) . "\">";
        echo "<input type=\"hidden\" name=\"step\" value=\"" . htmlspecialchars($current_step) . "\">";
        echo "<input type=\"hidden\" name=\"language\" value=\"" . htmlspecialchars($language) . "\">";
        echo "<input type=\"submit\" name=\"submit\" class=\"btn btn-primary btn-sm\" value=\"" . _('Go to step') . " " . htmlspecialchars($current_step) . "\">";
        echo "</form>";
        break;

    case 6:
        // No need to set database port if it's standard port for that db
        $db_port = ($_POST['db_type'] == 'mysql' && $_POST['db_port'] != 3306)
        || ($_POST['db_type'] == 'pgsql' && $_POST['db_port'] != 5432) ? $_POST['db_port'] : '';

        // For SQLite we should provide path to db file
        $db_file = $_POST['db_type'] == 'sqlite' ? $db_file = $_POST['db_name'] : '';

        $userAuthService = new UserAuthenticationService(
            $config->get('password_encryption'),
            $config->get('password_encryption_cost')
        );

        $session_key = $userAuthService->generateSalt(SESSION_KEY_LENGTH);
        $iface_lang = $language;
        $dns_hostmaster = $_POST['dns_hostmaster'];
        $dns_ns1 = $_POST['dns_ns1'];
        $dns_ns2 = $_POST['dns_ns2'];
        $dns_ns3 = ''; // $_POST['dns_ns3'];
        $dns_ns4 = ''; // $_POST['dns_ns4'];
        $db_host = $_POST['db_host'];
        $db_user = $_POST['pa_db_user'];
        $db_pass = $_POST['pa_db_pass'];
        $db_name = $_POST['db_name'];
        $db_type = $_POST['db_type'];
        $db_charset = $_POST['db_charset'];
        $pa_pass = $_POST['pa_pass'];

        $configuration = str_replace(
            [
                '%dbType%',
                '%dbFile%',
                '%dbHost%',
                '%dbPort%',
                '%dbUser%',
                '%dbPassword%',
                '%dbName%',
                '%dbCharset%',
                '%sessionKey%',
                '%locale%',
                '%hostMaster%',
                '%primaryNameServer%',
                '%secondaryNameServer%',
                '%thirdNameServer%',
                '%fourthNameServer%',
            ],
            [
                $db_type,
                $db_file,
                $db_host,
                $db_port,
                $db_user,
                $db_pass,
                $db_name,
                $db_charset,
                $session_key,
                $iface_lang,
                $dns_hostmaster,
                $dns_ns1,
                $dns_ns2,
                $dns_ns3,
                $dns_ns4,
            ],
            file_get_contents('includes/config_template.php')
        );

        // Try to create configuration file
        $config_file_created = false;

        if (is_writeable($local_config_file)) {
            $local_config = fopen($local_config_file, "w");
            fwrite($local_config, $configuration);
            fclose($local_config);
            $config_file_created = true;
        }

        $userAuthService = new UserAuthenticationService(
            $config->get('password_encryption'),
            $config->get('password_encryption_cost')
        );

        echo $twig->render('step6.html', array(
            'next_step' => (int)htmlspecialchars($current_step) + 1,
            'language' => htmlspecialchars($language),
            'config_file_created' => $config_file_created,
            'local_config_file' => $local_config_file,
            'session_key' => $userAuthService->generateSalt(SESSION_KEY_LENGTH),
            'iface_lang' => htmlspecialchars($language),
            'dns_hostmaster' => htmlspecialchars($dns_hostmaster),
            'dns_ns1' => htmlspecialchars($dns_ns1),
            'dns_ns2' => htmlspecialchars($dns_ns2),
            'db_host' => htmlspecialchars($db_host),
            'db_user' => htmlspecialchars($db_user),
            'db_pass' => htmlspecialchars($db_pass),
            'db_name' => htmlspecialchars($db_name),
            'db_file' => $db_type == 'sqlite' ? htmlspecialchars($db_name) : '',
            'db_type' => htmlspecialchars($db_type),
            'db_port' => htmlspecialchars($db_port),
            'db_charset' => htmlspecialchars($db_charset),
            'pa_pass' => htmlspecialchars($pa_pass)
        ));
        break;

    case 7:
        echo $twig->render('step7.html');
        break;

    default:
        break;
}

echo $twig->render('footer.html');

function getLocaleFile(string $iface_lang): string
{
    if (in_array($iface_lang, ['cs_CZ', 'de_DE', 'fr_FR', 'ja_JP', 'nb_NO', 'nl_NL', 'pl_PL', 'ru_RU', 'tr_TR', 'zh_CN'])) {
        $short_locale = substr($iface_lang, 0, 2);
        return "../locale/$iface_lang/LC_MESSAGES/$short_locale.po";
    }
    return "../locale/en_EN/LC_MESSAGES/en.po";
}