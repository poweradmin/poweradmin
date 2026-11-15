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
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * A {% trans %} block emits the translation unescaped, so inside a JS string
 * literal a translation with an apostrophe ends the string and kills the script.
 */
class TemplateJsTranslationEscapingTest extends TestCase
{
    private const CODES_MSGID = 'Store these codes in a safe place. Each code can be used once to log in if you lose access to your authenticator device.';

    /**
     * @return array<string, array{string}>
     */
    public static function themes(): array
    {
        return ['default' => ['default'], 'modern' => ['modern']];
    }

    #[DataProvider('themes')]
    public function testNoTransBlockSitsInsideAJsStringLiteral(string $theme): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 3) . '/templates/' . $theme . '/*.html') ?: [] as $file) {
            $inScript = false;
            foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
                if (preg_match('/<script\b/', $line) === 1) {
                    $inScript = true;
                }
                if ($inScript && $this->hasTransInsideQuotes($line)) {
                    $offenders[] = basename($file) . ':' . ($n + 1);
                }
                if (str_contains($line, '</script>')) {
                    $inScript = false;
                }
            }
        }

        $this->assertSame([], $offenders, 'use {{ \'text\'|trans|e(\'js\') }} inside JS strings');
    }

    #[DataProvider('themes')]
    public function testRecoveryCodesScriptSurvivesAnApostropheInTheTranslation(string $theme): void
    {
        $translator = new Translator('fr');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [self::CODES_MSGID => "Gardez ces codes. Perte de l'accès"], 'fr');

        $templates = dirname(__DIR__, 3) . '/templates';
        $twig = new Environment(new FilesystemLoader([$templates . '/' . $theme, $templates . '/default']), ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($translator));
        $twig->addGlobal('base_url_prefix', '');
        $twig->addGlobal('csrf_token', 'token');

        $html = $twig->render('mfa_recovery_codes.html', ['recovery_codes' => ['AAAA-BBBB'], 'mfa_type' => 'app']);

        $this->assertStringNotContainsString("l'accès", $html);
        $this->assertStringContainsString('l\\u0027acc', $html);
    }

    private function hasTransInsideQuotes(string $line): bool
    {
        if (preg_match_all('/\{%-? ?trans ?-?%\}/', $line, $m, PREG_OFFSET_CAPTURE) === 0) {
            return false;
        }
        foreach ($m[0] as [, $offset]) {
            $before = (string) preg_replace('/\\\\./', '', (string) preg_replace('/\{\{.*?\}\}|\{%.*?%\}/', '', substr($line, 0, $offset)));
            foreach (["'", '"', '`'] as $quote) {
                if (substr_count($before, $quote) % 2 === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
