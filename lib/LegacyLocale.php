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

namespace Poweradmin;

use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\Domain\Error\ErrorMessage;

class LegacyLocale
{
    public static function setAppLocale(string $iface_lang): void
    {
        if ($iface_lang == 'en_EN' || $iface_lang == 'en_US.UTF-8') {
            return;
        }

        if (!setlocale(LC_ALL, $iface_lang, $iface_lang . '.UTF-8')) {
            $error = new ErrorMessage(_('Failed to set locale. Selected locale may be unsupported on this system. Please contact your administrator.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
            exit();
        }

        $gettext_domain = 'messages';
        bindtextdomain($gettext_domain, './locale');
        bind_textdomain_codeset($gettext_domain, 'utf-8');
        textdomain($gettext_domain);
        @putenv('LANG=' . $iface_lang);
        @putenv('LANGUAGE=' . $iface_lang);
    }
}