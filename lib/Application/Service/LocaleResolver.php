<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Application\Service;

use Poweradmin\Application\Http\Request;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Resolves the interface language for the current request.
 *
 * Single source of truth for the locale precedence used by the gettext
 * chain (AppInitializer), the Twig translator (AppManager), and the page
 * chrome (PageRenderer) - they must all agree on the active locale.
 */
class LocaleResolver
{
    private const DEFAULT_LOCALE = 'en_EN';

    public function __construct(
        private readonly ConfigurationManager $config,
        private readonly UserContextService $userContext,
        private readonly Request $request
    ) {
    }

    /**
     * Resolves the active locale. Precedence: GET lang override (login page
     * language switcher) > session user language > interface.language config.
     *
     * @return string The resolved locale code
     */
    public function resolve(): string
    {
        $locale = $this->userContext->getUserLanguage()
            ?? $this->config->get('interface', 'language', self::DEFAULT_LOCALE)
            ?? self::DEFAULT_LOCALE;

        $requested = $this->request->getQueryParam('lang');
        if (
            is_string($requested)
            && preg_match('/^[a-zA-Z_]+$/', $requested)
            && in_array($requested, $this->getSupportedLocales(), true)
        ) {
            $locale = $requested;
        }

        return $locale;
    }

    /**
     * Returns the enabled locales from interface.enabled_languages, trimmed
     * and with empty entries dropped.
     *
     * @return list<string> The enabled locales; falls back to ['en_EN']
     */
    public function getSupportedLocales(): array
    {
        $enabledLanguages = $this->config->get('interface', 'enabled_languages');
        if (!is_string($enabledLanguages)) {
            return [self::DEFAULT_LOCALE];
        }

        $locales = array_values(array_filter(array_map('trim', explode(',', $enabledLanguages))));

        return $locales === [] ? [self::DEFAULT_LOCALE] : $locales;
    }
}
