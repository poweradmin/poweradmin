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

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;

/**
 * A translation with an apostrophe must not end the JS string it is printed into.
 */
class TemplateJsStringTranslationTest extends TestCase
{
    private const MSGID = 'Store these codes in a safe place. Each code can be used once to log in if you lose access to your authenticator device.';

    public function testRecoveryCodesPrintHandlerSurvivesApostropheInTranslation(): void
    {
        $translator = new Translator('fr');
        $translator->addLoader('array', new \Symfony\Component\Translation\Loader\ArrayLoader());
        $translator->addResource('array', [self::MSGID => "Gardez ces codes, l'acc\u{e8}s"], 'fr');

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates/default'));
        $twig->addExtension(new TranslationExtension($translator));
        $twig->addExtension(new IntlExtension());
        $twig->addGlobal('base_url_prefix', '');
        $twig->addGlobal('csrf_token', 'token');

        $html = $twig->render('mfa_recovery_codes.html', [
            'recovery_codes' => ['aaaa-bbbb'],
            'mfa_enabled' => true,
            'mfa_type' => 'app',
        ]);

        $this->assertStringNotContainsString("l'acc", $html);
        $this->assertStringContainsString('l\\u0027acc', $html);
    }

    public function testNoTransBlockSitsInsideAScriptElement(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/templates'));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'html') {
                continue;
            }
            preg_match_all('~<script\b[^>]*>(.*?)</script>~s', (string) file_get_contents($file->getPathname()), $blocks);
            foreach ($blocks[1] as $js) {
                if (preg_match('~\{%-?\s*trans\b~', $js)) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'use {{ \'text\'|trans|e(\'js\') }} inside JS');
    }
}
