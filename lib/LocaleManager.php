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

namespace Poweradmin;

/**
 * Class LocaleManager
 * Manages locale settings for the application.
 */
class LocaleManager
{
    /**
     * @var array $supportedLocales List of supported locales.
     */
    private array $supportedLocales;

    /**
     * @var string $localeDirectory Directory where locale files are stored.
     */
    private string $localeDirectory;

    /**
     * LocaleManager constructor.
     *
     * @param array $supportedLocales List of supported locales.
     * @param string $localeDirectory Directory where locale files are stored.
     */
    public function __construct(array $supportedLocales, string $localeDirectory)
    {
        $this->supportedLocales = $supportedLocales;
        $this->localeDirectory = $localeDirectory;
    }

    /**
     * Sets the locale for the application.
     *
     * @param string $locale The locale to set.
     * @return void
     */
    public function setLocale(string $locale): void
    {
        if (!in_array($locale, $this->supportedLocales)) {
            error_log("The provided locale '$locale' is not supported. Please choose a supported locale.");
            return;
        }

        if ($locale == 'en_EN' || $locale == 'en_EN.UTF-8') {
            return;
        }

        if (!is_dir($this->localeDirectory) || !is_readable($this->localeDirectory)) {
            error_log("The directory '$this->localeDirectory' does not exist or is not readable.");
            return;
        }

        $locales = ["$locale.UTF-8", "$locale.utf8", $locale];

        if (!setlocale(LC_ALL, $locales)) {
            error_log("Failed to set locale '$locale'. Selected locale may be unsupported on this system.");
            return;
        }

        $domain = 'messages';
        bindtextdomain($domain, $this->localeDirectory);
        bind_textdomain_codeset($domain, 'utf-8');
        textdomain($domain);
        @putenv('LANG=' . $locale);
        @putenv('LANGUAGE=' . $locale);
    }
}
