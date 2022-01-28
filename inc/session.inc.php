<?php

/** Logout the user
 *
 * Logout the user and kickback to login form
 *
 * @param string $msg Error Message
 * @param string $type Message type [default='']
 *
 * @return null
 */
function logout($msg = "", $type = "") {
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
function auth($msg = "", $type = "success") {
    include_once 'inc/header.inc.php';
    include_once 'inc/config.inc.php';
    global $iface_lang;

    if ($msg) {
        print "<div class=\"$type\">$msg</div>\n";
    }
    ?>
    <h2><?php echo _('Log in'); ?></h2>
    <form method="post" action="<?php echo htmlentities($_SERVER["PHP_SELF"], ENT_QUOTES); ?>">
        <input type="hidden" name="query_string" value="<?php echo htmlentities($_SERVER["QUERY_STRING"]); ?>">
        <table border="0">
            <tr>
                <td class="n" width="100"><?php echo _('Username'); ?>:</td>
                <td class="n"><input type="text" class="input" name="username" id="username"></td>
            </tr>
            <tr>
                <td class="n"><?php echo _('Password'); ?>:</td>
                <td class="n"><input type="password" class="input" name="password"></td>
            </tr>
            <tr>
                <td class="n"><?php echo _('Language'); ?>:</td>
                <td class="n">
                    <select class="input" name="userlang">
                        <?php
                        // List available languages (sorted alphabetically)
                        $locales = scandir('locale/');
                        foreach ($locales as $locale) {
                            if (strlen($locale) == 5) {
                                $locales_fullname[$locale] = get_country_code($locale);
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
                </td>
            </tr>
            <tr>
                <td class="n">&nbsp;</td>
                <td class="n">
                    <input type="submit" name="authenticate" class="button" value=" <?php echo _('Go'); ?> ">
                </td>
            </tr>
        </table>
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
