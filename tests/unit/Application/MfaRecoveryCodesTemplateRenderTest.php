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

namespace Poweradmin\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;

/**
 * Disabling MFA needs a current factor, so the button on the recovery codes page
 * must submit an mfa_code field alongside it.
 */
class MfaRecoveryCodesTemplateRenderTest extends TestCase
{
    public static function themeProvider(): array
    {
        return [
            'default theme' => ['default'],
            'modern theme' => ['modern'],
        ];
    }

    #[DataProvider('themeProvider')]
    public function testDisableFormCarriesTheCurrentCodeField(string $theme): void
    {
        $html = $this->render($theme);

        preg_match('~<form\b(?:(?!</form>).)*name="disable_mfa"(?:(?!</form>).)*</form>~s', $html, $form);
        $this->assertNotEmpty($form, 'disable_mfa form not rendered');
        $this->assertStringContainsString('name="mfa_code"', $form[0]);
    }

    private function render(string $theme): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates/' . $theme));
        $twig->addExtension(new TranslationExtension(new Translator('en')));
        $twig->addExtension(new IntlExtension());
        $twig->addGlobal('base_url_prefix', '');
        $twig->addGlobal('csrf_token', 'token');

        return $twig->render('mfa_recovery_codes.html', [
            'recovery_codes' => ['aaaa-bbbb'],
            'mfa_enabled' => true,
            'mfa_type' => 'app',
        ]);
    }
}
