<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
    public const DEFAULT_LANGUAGE = 'en_EN';

    private const SUPPORTED_LANGUAGES = [
        'cs_CZ', 'de_DE', 'fr_FR', 'it_IT', 'ja_JP', 'lt_LT', 'nb_NO', 'nl_NL', 'pl_PL', 'ru_RU', 'tr_TR', 'zh_CN', 'en_EN'
    ];

    public static function getAvailableLanguages(): array
    {
        return self::SUPPORTED_LANGUAGES;
    }

    public function getLocaleFile(string $language): string
    {
        if ($this->isSupportedLanguage($language)) {
            return dirname(__DIR__, 2) . "/locale/$language/LC_MESSAGES/messages.po";
        }
        return dirname(__DIR__, 2) . "/locale/" . self::DEFAULT_LANGUAGE . "/LC_MESSAGES/messages.po";
    }

    public function getCurrentLanguage(mixed $language): string
    {
        if ($this->isSupportedLanguage($language)) {
            return $language;
        }

        return self::DEFAULT_LANGUAGE;
    }

    public function setLanguage(string $language): void
    {
        if ($language != self::DEFAULT_LANGUAGE) {
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
