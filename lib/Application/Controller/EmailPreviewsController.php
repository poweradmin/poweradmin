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

use Poweradmin\BaseController;
use Poweradmin\Application\Service\EmailTemplateService;
use Poweradmin\Application\Service\EmailPreviewService;

class EmailPreviewsController extends BaseController
{
    private EmailTemplateService $emailTemplateService;
    private EmailPreviewService $emailPreviewService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->emailTemplateService = new EmailTemplateService($this->config);
        $this->emailPreviewService = new EmailPreviewService($this->emailTemplateService);
    }

    public function run(): void
    {
        if (!$this->config->get('misc', 'email_previews_enabled', false)) {
            $this->showError('The email template preview feature is disabled in the system configuration.');
            return;
        }

        $this->checkPermission('user_is_ueberuser', 'You do not have permission to access email template previews.');

        $template = $this->getSafeRequestValue('template');
        $mode = $this->getSafeRequestValue('mode');

        if ($template && $mode) {
            $this->renderEmailPreview($template, $mode);
            return;
        }

        $previews = $this->emailPreviewService->generateAllPreviews();

        $this->render('email_previews.html', [
            'page_title' => 'Email Template Previews',
            'previews' => $previews,
            'template_names' => [
                'new-account' => 'New Account',
                'password-reset' => 'Password Reset',
                'mfa-verification' => 'MFA Verification'
            ]
        ]);
    }

    private function renderEmailPreview(string $template, string $mode): void
    {
        header('Content-Type: text/html; charset=utf-8');

        try {
            switch ($template) {
                case 'new-account':
                    $preview = $this->emailPreviewService->generateNewAccountPreview();
                    break;
                case 'password-reset':
                    $preview = $this->emailPreviewService->generatePasswordResetPreview();
                    break;
                case 'mfa-verification':
                    $preview = $this->emailPreviewService->generateMfaVerificationPreview();
                    break;
                default:
                    http_response_code(404);
                    echo '<h1>Template not found</h1>';
                    return;
            }

            if ($mode === 'dark') {
                echo $preview['dark'];
            } else {
                echo $preview['light'];
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo '<h1>Error generating preview</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>';
        }

        exit;
    }
}
