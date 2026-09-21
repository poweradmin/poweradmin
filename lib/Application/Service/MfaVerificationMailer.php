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

use Poweradmin\Domain\Port\MfaVerificationMailerInterface;
use Poweradmin\Domain\Config\ConfigurationInterface;

/**
 * Renders the MFA verification email through Twig and sends it with MailService.
 */
class MfaVerificationMailer implements MfaVerificationMailerInterface
{
    private MailService $mailService;
    private EmailTemplateService $templateService;

    public function __construct(MailService $mailService, ConfigurationInterface $config)
    {
        $this->mailService = $mailService;
        $this->templateService = new EmailTemplateService($config);
    }

    public function isMailConfigurationValid(): bool
    {
        return $this->mailService->isMailConfigurationValid();
    }

    public function sendVerificationCode(string $email, string $code, int $expiresAt, ?string $timezone): void
    {
        $templates = $this->templateService->renderMfaVerificationEmail($code, $expiresAt, $timezone);
        $this->mailService->sendMail($email, $templates['subject'], $templates['html'], $templates['text']);
    }
}
