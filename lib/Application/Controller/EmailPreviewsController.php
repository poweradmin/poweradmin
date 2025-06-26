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
        $isCustom = $this->getSafeRequestValue('custom') === 'true';

        if ($template && $mode) {
            $this->renderEmailPreview($template, $mode, $isCustom);
            return;
        }

        $previewData = $this->emailPreviewService->generateAllPreviews();

        $this->render('email_previews.html', [
            'page_title' => 'Email Template Previews',
            'templates' => $previewData['templates'],
            'using_custom' => $previewData['using_custom'],
            'template_names' => [
                'new-account' => 'New Account',
                'password-reset' => 'Password Reset',
                'mfa-verification' => 'MFA Verification'
            ]
        ]);
    }

    private function renderEmailPreview(string $template, string $mode, bool $isCustom = false): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');

        try {
            $previewData = $this->emailPreviewService->generateAllPreviews();
            $templates = $previewData['templates'];

            if (!isset($templates[$template])) {
                http_response_code(404);
                echo '<h1>Template not found</h1>';
                return;
            }

            $preview = $templates[$template];

            if ($mode === 'dark') {
                echo $preview['dark'];
            } else {
                echo $preview['light'];
            }
        } catch (\Exception $e) {
            http_response_code(500);
            if ($this->config->get('misc', 'display_errors', false)) {
                echo '<h1>Error generating preview</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>';
            } else {
                echo '<h1>Error generating preview</h1><p>An error occurred while generating the email preview. Please contact your administrator.</p>';
            }
        }

        exit;
    }
}
