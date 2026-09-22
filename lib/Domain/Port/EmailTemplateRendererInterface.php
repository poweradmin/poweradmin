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

namespace Poweradmin\Domain\Port;

/**
 * Renders the account emails a preview tool can show; the Application layer
 * owns the templates and their custom overrides.
 */
interface EmailTemplateRendererInterface
{
    /**
     * @return array{html: string, text: string, subject: string}
     */
    public function renderNewAccountEmail(string $username, #[\SensitiveParameter] string $password, string $fullname = ''): array;

    /**
     * @return array{html: string, text: string, subject: string}
     */
    public function renderPasswordResetEmail(string $name, string $resetUrl, int $expireMinutes): array;

    /**
     * @param int $expiresAt Unix timestamp the code stops being accepted
     * @param string|null $timezone IANA timezone the expiry is shown in, or the configured default
     * @return array{html: string, text: string, subject: string}
     */
    public function renderMfaVerificationEmail(string $verificationCode, int $expiresAt, ?string $timezone = null): array;

    /**
     * Whether an operator-supplied template file overrides the shipped one.
     */
    public function hasCustomTemplate(string $template): bool;
}
