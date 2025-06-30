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

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class EmailTemplateService
{
    private Environment $twig;
    private ConfigurationManager $config;
    private string $defaultTemplatePath;
    private string $customTemplatePath;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->defaultTemplatePath = __DIR__ . '/../../../templates/emails';
        $this->customTemplatePath = __DIR__ . '/../../../templates/emails/custom';

        $this->initializeTwig();
    }

    private function initializeTwig(): void
    {
        $paths = [];

        // Add custom template path first (higher priority)
        if (is_dir($this->customTemplatePath)) {
            $paths[] = $this->customTemplatePath;
        }

        // Add default template path as fallback
        $paths[] = $this->defaultTemplatePath;

        $loader = new FilesystemLoader($paths);

        $this->twig = new Environment($loader, [
            'cache' => false, // Disable cache for development, can be enabled in production
            'debug' => $this->config->get('misc', 'display_errors', false),
            'strict_variables' => true,
        ]);

        // Add global variables available to all templates
        $this->twig->addGlobal('appName', $this->config->get('interface', 'title', 'Poweradmin'));
    }

    /**
     * Render an email template
     *
     * @param string $template Template name (e.g., 'new-account.html.twig')
     * @param array $variables Variables to pass to the template
     * @return string Rendered template content
     * @throws LoaderError|RuntimeError|SyntaxError
     */
    public function render(string $template, array $variables = []): string
    {
        return $this->twig->render($template, $variables);
    }

    /**
     * Render new account email templates
     *
     * @param string $username User's username
     * @param string $password User's password
     * @param string $fullname User's full name
     * @return array ['html' => string, 'text' => string, 'subject' => string]
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function renderNewAccountEmail(string $username, string $password, string $fullname = ''): array
    {
        $greeting = empty($fullname) ? 'Hello' : "Hello $fullname";

        $variables = [
            'greeting' => $greeting,
            'username' => $username,
            'password' => $password,
            'fullname' => $fullname,
        ];

        return [
            'html' => $this->render('new-account.html.twig', $variables),
            'text' => $this->render('new-account.txt.twig', $variables),
            'subject' => 'Your new account information'
        ];
    }

    /**
     * Render password reset email templates
     *
     * @param string $name User's name
     * @param string $resetUrl Password reset URL
     * @param int $expireMinutes Expiration time in minutes
     * @return array ['html' => string, 'text' => string, 'subject' => string]
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function renderPasswordResetEmail(string $name, string $resetUrl, int $expireMinutes): array
    {
        $greeting = $name ? "Hi $name," : "Hi,";

        $variables = [
            'greeting' => $greeting,
            'name' => $name,
            'resetUrl' => $resetUrl,
            'expireMinutes' => $expireMinutes,
        ];

        return [
            'html' => $this->render('password-reset.html.twig', $variables),
            'text' => $this->render('password-reset.txt.twig', $variables),
            'subject' => 'Password Reset Request'
        ];
    }

    /**
     * Render MFA verification email templates
     *
     * @param string $verificationCode Verification code
     * @param int $expiresAt Expiration timestamp
     * @return array ['html' => string, 'text' => string, 'subject' => string]
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function renderMfaVerificationEmail(string $verificationCode, int $expiresAt): array
    {
        $expireTime = date('H:i:s', $expiresAt);

        $variables = [
            'verificationCode' => $verificationCode,
            'expireTime' => $expireTime,
            'expiresAt' => $expiresAt,
        ];

        return [
            'html' => $this->render('mfa-verification.html.twig', $variables),
            'text' => $this->render('mfa-verification.txt.twig', $variables),
            'subject' => 'Your verification code'
        ];
    }

    /**
     * Check if a custom template exists
     *
     * @param string $template Template name
     * @return bool
     */
    public function hasCustomTemplate(string $template): bool
    {
        return file_exists($this->customTemplatePath . '/' . $template);
    }

    /**
     * Get available template paths for debugging
     *
     * @return array
     */
    public function getTemplatePaths(): array
    {
        return [
            'custom' => $this->customTemplatePath,
            'default' => $this->defaultTemplatePath,
        ];
    }
}
