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

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Presenter\LocalePresenter;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\LocaleService;
use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Utility\LanguageCode;

include_once(__DIR__ . '/../../../inc/config-defaults.inc.php');
@include_once(__DIR__ . '/../../../inc/config.inc.php');

class LoginController extends BaseController
{
    private LocaleService $localeService;
    private LocalePresenter $localePresenter;
    private CsrfTokenService $csrfTokenService;

    public function __construct(array $request)
    {
        parent::__construct($request, false);

        $this->localeService = new LocaleService();
        $this->localePresenter = new LocalePresenter();
        $this->csrfTokenService = new CsrfTokenService();
    }

    public function run(): void
    {
        $localesData = $this->getLocalesData();
        $preparedLocales = $this->localeService->prepareLocales($localesData, $this->config('iface_lang'));

        list($msg, $type) = $this->getSessionMessages();

        if (file_exists('install')) {
            $this->render('empty.html', []);
        } else {
            $this->renderLogin($preparedLocales, $msg, $type);
        }
    }

    private function getSessionMessages(): array
    {
        $msg = $_SESSION['message'] ?? '';
        $type = $_SESSION['type'] ?? '';
        unset($_SESSION['message'], $_SESSION['type']);
        return [$msg, $type];
    }

    private function renderLogin(array $preparedLocales, string $msg, string $type): void
    {
        $locales = explode(',', $this->config('iface_enabled_languages'));
        $showLanguageSelector = count($locales) > 1;

        $loginToken = $this->csrfTokenService->generateToken();
        $_SESSION['login_token'] = $loginToken;

        $this->render('login.html', [
            'login_token' => $loginToken,
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'locale_options' => $this->localePresenter->generateLocaleOptions($preparedLocales),
            'show_language_selector' => $showLanguageSelector,
            'msg' => $msg,
            'type' => $type,
        ]);
    }

    private function getLocalesData(): array
    {
        $locales = explode(',', $this->config('iface_enabled_languages'));
        $localesData = [];
        foreach ($locales as $locale) {
            $localesData[$locale] = LanguageCode::getByLocale($locale);
        }
        asort($localesData);

        return $localesData;
    }
}
