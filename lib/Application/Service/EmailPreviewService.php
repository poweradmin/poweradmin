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

namespace Poweradmin\Application\Service;

class EmailPreviewService
{
    private EmailTemplateService $emailTemplateService;

    public function __construct(EmailTemplateService $emailTemplateService)
    {
        $this->emailTemplateService = $emailTemplateService;
    }

    public function generateNewAccountPreview(bool $forceDarkMode = false): array
    {
        $sampleData = $this->emailTemplateService->renderNewAccountEmail(
            'john.doe',
            'SecureP@ssw0rd123',
            'John Doe'
        );

        return [
            'light' => $sampleData['html'],
            'dark' => $this->forceDarkMode($sampleData['html']),
            'subject' => $sampleData['subject']
        ];
    }

    public function generatePasswordResetPreview(bool $forceDarkMode = false): array
    {
        $sampleData = $this->emailTemplateService->renderPasswordResetEmail(
            'Jane Smith',
            'https://poweradmin.example.com/reset?token=abc123def456',
            30
        );

        return [
            'light' => $sampleData['html'],
            'dark' => $this->forceDarkMode($sampleData['html']),
            'subject' => $sampleData['subject']
        ];
    }

    public function generateMfaVerificationPreview(bool $forceDarkMode = false): array
    {
        $sampleData = $this->emailTemplateService->renderMfaVerificationEmail(
            '123456',
            time() + 300
        );

        return [
            'light' => $sampleData['html'],
            'dark' => $this->forceDarkMode($sampleData['html']),
            'subject' => $sampleData['subject']
        ];
    }

    public function generateAllPreviews(): array
    {
        // Check if custom templates exist first
        $customPreviews = $this->generateCustomPreviews();

        if (!empty($customPreviews)) {
            // If custom templates exist, use only those (they override standard)
            return [
                'templates' => $customPreviews,
                'using_custom' => true
            ];
        } else {
            // If no custom templates, use standard templates
            return [
                'templates' => [
                    'new-account' => $this->generateNewAccountPreview(),
                    'password-reset' => $this->generatePasswordResetPreview(),
                    'mfa-verification' => $this->generateMfaVerificationPreview()
                ],
                'using_custom' => false
            ];
        }
    }

    public function generateCustomPreviews(): array
    {
        $customPreviews = [];
        $customTemplatePath = __DIR__ . '/../../../templates/emails/custom';

        if (!is_dir($customTemplatePath)) {
            return $customPreviews;
        }

        // Check for custom versions of standard templates
        $standardTemplates = ['new-account', 'password-reset', 'mfa-verification'];

        foreach ($standardTemplates as $templateName) {
            if ($this->emailTemplateService->hasCustomTemplate($templateName . '.html.twig')) {
                $customPreviews[$templateName] = $this->generateCustomTemplatePreview($templateName);
            }
        }

        return $customPreviews;
    }

    private function generateCustomTemplatePreview(string $templateName): array
    {
        switch ($templateName) {
            case 'new-account':
                return $this->generateNewAccountPreview();
            case 'password-reset':
                return $this->generatePasswordResetPreview();
            case 'mfa-verification':
                return $this->generateMfaVerificationPreview();
            default:
                throw new \InvalidArgumentException("Unknown template: $templateName");
        }
    }

    private function forceDarkMode(string $html): string
    {
        // Force dark mode by overriding the media query with a style that applies dark mode classes
        $darkModeOverride = '
        <style>
            /* Force dark mode styles */
            .email-container {
                background-color: #1a1a1a !important;
                color: #ffffff !important;
            }
            .email-content {
                background-color: #1a1a1a !important;
                color: #ffffff !important;
            }
            .table-header {
                background-color: #2d2d2d !important;
                color: #ffffff !important;
            }
            .table-border {
                border-color: #444444 !important;
            }
            .button-primary {
                background-color: #4a90e2 !important;
                color: #ffffff !important;
            }
            .code-container {
                background-color: #2d2d2d !important;
                border-color: #444444 !important;
                color: #ffffff !important;
            }
            .url-container {
                background-color: #2d2d2d !important;
                color: #ffffff !important;
            }
            .divider {
                border-color: #444444 !important;
            }
            .disclaimer-text {
                color: #cccccc !important;
            }
            .heading {
                color: #5dade2 !important;
            }
        </style>';

        // Insert the dark mode override before the closing </head> tag
        return str_replace('</head>', $darkModeOverride . '</head>', $html);
    }

    public function savePreviewsToFiles(string $outputDir = 'email-previews'): array
    {
        // Sanitize output directory to prevent path traversal
        $outputDir = basename($outputDir);
        $outputDir = preg_replace('/[^a-zA-Z0-9_-]/', '', $outputDir);

        if (empty($outputDir)) {
            $outputDir = 'email-previews';
        }

        // Ensure the directory is relative and safe - create in project root
        $safeOutputDir = $outputDir;

        if (!is_dir($safeOutputDir)) {
            if (!mkdir($safeOutputDir, 0755, true)) {
                throw new \RuntimeException('Failed to create preview directory: ' . $safeOutputDir);
            }
        }

        $previewData = $this->generateAllPreviews();
        $templates = $previewData['templates'];
        $usingCustom = $previewData['using_custom'];
        $savedFiles = [];

        foreach ($templates as $templateName => $templateData) {
            $suffix = $usingCustom ? '-custom' : '';
            $lightFile = $safeOutputDir . '/' . $templateName . $suffix . '-light.html';
            $darkFile = $safeOutputDir . '/' . $templateName . $suffix . '-dark.html';

            file_put_contents($lightFile, $templateData['light']);
            file_put_contents($darkFile, $templateData['dark']);

            $savedFiles[$templateName . $suffix] = [
                'light' => $lightFile,
                'dark' => $darkFile,
                'subject' => $templateData['subject']
            ];
        }

        return $savedFiles;
    }
}
