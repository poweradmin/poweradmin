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

use Poweradmin\Infrastructure\Web\LanguageCode;

function logout(string $msg = "", string $type = "") {
    session_regenerate_id(true);
    session_unset();
    session_destroy();
    session_write_close();
    auth($msg, $type);
    exit;
}

function get_locales(): array
{
    $localeFolders = scandir('locale/');
    foreach ($localeFolders as $folder) {
        if (strlen($folder) == 5) {
            $locales[$folder] = LanguageCode::getByLocale($folder);
        }
    }
    asort($locales);

    return $locales;
}

function prepareLocales($locales, $iface_lang): array
{
    $preparedLocales = [];
    foreach ($locales as $locale => $language) {
        $isSelected = substr($locale, 0, 2) == substr($iface_lang, 0, 2);
        $preparedLocales[] = [
            'locale' => $locale,
            'language' => $language,
            'selected' => $isSelected
        ];
    }
    return $preparedLocales;
}

function generateLocaleOptions($locales): string
{
    $html = '';
    foreach ($locales as $locale) {
        $selectedAttr = $locale['selected'] ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($locale['locale']) . '"' . $selectedAttr . '>' . htmlspecialchars($locale['language']) . '</option>';
    }
    return $html;
}

function auth(string $msg = "", string $type = "success") {
    include_once 'inc/header.inc.php';
    include_once 'inc/config.inc.php';

    global $iface_lang;
    $locales = get_locales();
    $preparedLocales = prepareLocales($locales, $iface_lang);
    $localeOptions = generateLocaleOptions($preparedLocales);

    if ($msg) {
        print "<div class=\"alert alert-{$type}\">{$msg}</div>\n";
    }
    ?>
    <h5><?= _('Log in'); ?></h5>
    <form class="needs-validation" method="post" action="index.php" novalidate>
        <input type="hidden" name="query_string" value="<?= htmlentities($_SERVER["QUERY_STRING"]); ?>">
        <div class="row g-2 col-sm-4">
            <div>
                <label for="username" class="form-label"><?= _('Username'); ?></label>
                <input type="text" class="form-control form-control-sm" id="username" name="username" required>
                <div class="invalid-feedback"><?= _('Please provide a username'); ?></div>
            </div>
            <div>
                <label for="password" class="form-label"><?= _('Password'); ?></label>
                <div class="input-group">
                    <input type="password" class="form-control form-control-sm" id="password" name="password" required>
                    <button class="btn btn-sm btn-outline-secondary" type="button" onclick="showPassword('password', 'eye')"><i class="bi bi-eye-fill" id="eye"></i></button>
                    <div class="invalid-feedback"><?= _('Please provide a password'); ?></div>
                </div>
            </div>
            <div>
                <label for="language" class="form-label"><?= _('Language'); ?></label>
                <select class="form-select form-select-sm" name="userlang">
                    <?= $localeOptions; ?>
                </select>
            </div>
            <div>
                <input type="submit" name="authenticate" class="btn btn-primary btn-sm" value=" <?= _('Go'); ?> ">
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
