<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
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

namespace PoweradminInstall;

use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\Domain\Error\ErrorMessage;

class LocaleHandler
{
    private const SUPPORTED_LANGUAGES = [
        'cs_CZ', 'de_DE', 'fr_FR', 'ja_JP', 'lt_LT', 'nb_NO', 'nl_NL', 'pl_PL', 'ru_RU', 'tr_TR', 'zh_CN', 'en_EN'
    ];

    public function getLocaleFile(string $iface_lang): string
    {
        if ($this->isSupportedLanguage($iface_lang)) {
            return dirname(__DIR__, 2) . "/locale/$iface_lang/LC_MESSAGES/messages.po";
        }
        return dirname(__DIR__, 2) . "/locale/en_EN/LC_MESSAGES/messages.po";
    }

    public function getLanguageFromRequest(): string
    {
        $defaultLanguage = 'en_EN';

        if (isset($_POST['language']) && $this->isSupportedLanguage($_POST['language'])) {
            return $_POST['language'];
        }

        return $defaultLanguage;
    }

    public function setLanguage($language): void
    {
        if ($language != 'en_EN') {
            $locales = [
                $language . '.UTF-8',
                $language . '.utf8',
                $language,
            ];

            $locale = setlocale(LC_ALL, $locales);
            if (!$locale) {
                $this->handleError();
            }

            $gettext_domain = 'messages';
            bindtextdomain($gettext_domain, "./../locale");
            textdomain($gettext_domain);
            @putenv('LANG=' . $language);
            @putenv('LANGUAGE=' . $language);
        }
    }

    private function isSupportedLanguage(string $language): bool
    {
        return in_array($language, self::SUPPORTED_LANGUAGES, true);
    }

    public function handleError(): void
    {
        $error = new ErrorMessage(_('Failed to set locale. Selected locale may be unsupported on this system. Please contact your administrator.'));
        $errorPresenter = new ErrorPresenter();
        $errorPresenter->present($error);
    }
}
