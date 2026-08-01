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

namespace Poweradmin\Application\Presenter;

use Poweradmin\Domain\Error\ErrorMessage;

class ErrorPresenter
{
    public function present(ErrorMessage $error): void
    {
        $msg = $this->sanitizeMessage($error->getMessage(), $error->allowsHtml());

        // renderError() escapes the name; doing it here too would double-encode it
        $this->renderError($msg, $error->getName() ?? '');
    }

    /**
     * Messages are escaped unless the caller opted in. strip_tags() is not enough
     * on its own - it keeps the attributes of any tag it allows through, so the
     * opt-in branch is reserved for hardcoded messages.
     */
    private function sanitizeMessage(string $message, bool $allowHtml): string
    {
        if (!$allowHtml) {
            return htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        }

        return strip_tags($message, '<a>');
    }

    private function renderError(string $msg, string $name): void
    {
        $safeName = !empty($name) ? htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : null;
        $errorContent = ($safeName !== null) ? "$msg (Record: $safeName)" : $msg;

        echo <<<HTML
        <div class="alert alert-danger">
            <strong>Error:</strong> {$errorContent}
        </div>
        HTML;
    }
}
