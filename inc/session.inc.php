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

use Poweradmin\CountryCode;

/** Logout the user
 *
 * Logout the user and kickback to login form
 *
 * @param string $msg Error Message
 * @param string $type Message type [default='']
 *
 * @return null
 */
function logout(string $msg = "", string $type = "") {
    session_unset();
    session_destroy();
    session_write_close();
    auth($msg, $type);
    exit;
}

/** Print the login form
 *
 * @param string $msg Error Message
 * @param string $type Message type [default='success', 'error']
 *
 * @return null
 */
function auth(string $msg = "", string $type = "success") {
    include_once 'inc/header.inc.php';
    include_once 'inc/config.inc.php';
    global $iface_lang;

    if ($msg) {
        print "<div class=\"alert alert-{$type}\">{$msg}</div>\n";
    }
    ?>
    <h5><?php echo _('Log in'); ?></h5>
    <form class="needs-validation" method="post" action="index.php" novalidate>
        <input type="hidden" name="query_string" value="<?php echo htmlentities($_SERVER["QUERY_STRING"]); ?>">
        <div class="row g-2 col-sm-4">
            <div>
                <label for="username" class="form-label"><?php echo _('Username'); ?></label>
                <input type="text" class="form-control form-control-sm" id="username" name="username" required>
                <div class="invalid-feedback"><?php echo _('Please provide a username'); ?></div>
            </div>
            <div>
                <label for="password" class="form-label"><?php echo _('Password'); ?></label>
                <input type="password" class="form-control form-control-sm" id="password" name="password" required>
                <div class="invalid-feedback"><?php echo _('Please provide a password'); ?></div>
            </div>
            <div>
                <label for="language" class="form-label"><?php echo _('Language'); ?></label>
                <select class="form-select form-select-sm" name="userlang">
                    <?php
                    // List available languages (sorted alphabetically)
                    $locales = scandir('locale/');
                    foreach ($locales as $locale) {
                        if (strlen($locale) == 5) {
                            $locales_fullname[$locale] = CountryCode::getByLocale($locale);
                        }
                    }
                    asort($locales_fullname);
                    foreach ($locales_fullname as $locale => $language) {
                        if (substr($locale, 0, 2) == substr($iface_lang, 0, 2)) {
                            echo _('<option selected value="' . $locale . '">' . $language);
                        } else {
                            echo _('<option value="' . $locale . '">' . $language);
                        }
                    }
                    ?>
                </select>
            </div>
            <div>
                <input type="submit" name="authenticate" class="btn btn-primary btn-sm" value=" <?php echo _('Go'); ?> ">
            </div>
        </div>
    </form>
    <script type="text/javascript">
        <!--
        document.getElementById('username').focus();
        //-->
    </script>
    <?php
    include_once('inc/footer.inc.php');
    exit;
}
