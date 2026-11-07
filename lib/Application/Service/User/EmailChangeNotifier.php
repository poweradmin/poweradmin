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

namespace Poweradmin\Application\Service\User;

use Poweradmin\Application\Service\Mail\EmailTemplateService;
use Poweradmin\Application\Service\Mail\MailService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Port\EmailChangeNotifierInterface;

/**
 * Renders the email-changed notice through Twig and sends it with MailService.
 */
final class EmailChangeNotifier implements EmailChangeNotifierInterface
{
    private EmailTemplateService $templateService;

    public function __construct(private readonly MailService $mailService, private readonly ConfigurationInterface $config)
    {
        $this->templateService = new EmailTemplateService($config);
    }

    public function notifyEmailChanged(string $oldEmail, string $newEmail, string $name): bool
    {
        // Nothing to send counts as done, so installs without mail log no warning on every edit
        if (!$this->config->get('mail', 'enabled', false)) {
            return true;
        }

        $templates = $this->templateService->renderEmailChangedEmail($name, $newEmail, date('Y-m-d H:i:s'));

        return $this->mailService->sendMail($oldEmail, $templates['subject'], $templates['html'], $templates['text']);
    }
}
