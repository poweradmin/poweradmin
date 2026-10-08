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

namespace Poweradmin\Tests\Unit\Application\Presenter;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ApiKey;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;

/**
 * The browser decodes HTML entities in an onclick attribute before running it,
 * so the key name must be JS-escaped or a quote in it ends the JS string.
 */
class ApiKeysTemplateRenderTest extends TestCase
{
    private const HOSTILE_NAME = "x'); alert(1); ('";

    /**
     * @return array<string, array{string}>
     */
    public static function themes(): array
    {
        return ['default' => ['default'], 'modern' => ['modern']];
    }

    #[DataProvider('themes')]
    public function testKeyNameStaysInsideTheJsStringOfTheToggleHandlers(string $theme): void
    {
        $handlers = $this->onclickHandlers($this->render($theme));

        $this->assertCount(2, $handlers);
        foreach ($handlers as $handler) {
            $this->assertSame(4, substr_count($handler, "'"), $handler);
            $this->assertStringNotContainsString('alert(1);', $handler);
        }
    }

    private function render(string $theme): string
    {
        $templates = dirname(__DIR__, 4) . '/templates';
        $loader = new FilesystemLoader([$templates . '/' . $theme, $templates . '/default']);
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension(new Translator('en')));
        $twig->addExtension(new IntlExtension());
        $twig->addGlobal('base_url_prefix', '');
        $twig->addGlobal('csrf_token', 'token');

        $enabled = new ApiKey(self::HOSTILE_NAME, 'secret', 1, null, null, false, null, 5);
        $disabled = new ApiKey(self::HOSTILE_NAME, 'secret', 1, null, null, true, null, 6);

        return $twig->render('api_keys.html', [
            'api_keys' => [$enabled, $disabled],
            'can_add_more' => true,
            'max_keys_per_user' => 5,
        ]);
    }

    /**
     * @return list<string> decoded onclick values, as the browser hands them to the JS engine
     */
    private function onclickHandlers(string $html): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $handlers = [];
        foreach ((new DOMXPath($dom))->query('//button[@onclick]') ?: [] as $button) {
            if ($button instanceof DOMElement && str_contains($button->getAttribute('onclick'), '/toggle')) {
                $handlers[] = $button->getAttribute('onclick');
            }
        }

        return $handlers;
    }
}
