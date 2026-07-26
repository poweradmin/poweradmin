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
 */

namespace Poweradmin\Tests\Unit\Application\Presenter;

use PHPUnit\Framework\TestCase;
use Poweradmin\AppManager;
use Poweradmin\Application\Presenter\PageRenderer;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Covers the locale-resolution and language-selector logic of PageRenderer,
 * the pure parts of the page chrome pipeline extracted from BaseController.
 */
class PageRendererTest extends TestCase
{
    private array $originalGet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalGet = $_GET;
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
        parent::tearDown();
    }

    private function makeRenderer(array $interfaceConfig, ?string $userLanguage = null): PageRenderer
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            function (string $group, string $key, $default = null) use ($interfaceConfig) {
                return $interfaceConfig[$group][$key] ?? $default;
            }
        );

        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getUserLanguage')->willReturn($userLanguage);

        return new PageRenderer(
            $this->createMock(AppManager::class),
            $config,
            $this->createMock(CsrfTokenService::class),
            $userContext,
            fn(string $permission): bool => false,
            fn() => null,
            fn() => []
        );
    }

    public function testSingleEnabledLanguageHidesSelector(): void
    {
        $renderer = $this->makeRenderer(['interface' => ['enabled_languages' => 'en_EN']]);

        $vars = $renderer->getLanguageSelectorVars('en_EN');

        $this->assertSame('en_EN', $vars['current_language']);
        $this->assertArrayNotHasKey('locales', $vars);
        $this->assertArrayNotHasKey('show_language_selector', $vars);
    }

    public function testMultipleLanguagesBuildSortedSelector(): void
    {
        $renderer = $this->makeRenderer(['interface' => ['enabled_languages' => 'en_EN,de_DE,fr_FR']]);

        $vars = $renderer->getLanguageSelectorVars('de_DE');

        $this->assertTrue($vars['show_language_selector']);
        $this->assertCount(3, $vars['locales']);

        // Sorted by human-readable language name, not locale code
        $names = array_column($vars['locales'], 'language');
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);

        $selected = array_values(array_filter($vars['locales'], fn(array $l) => $l['selected']));
        $this->assertCount(1, $selected);
        $this->assertSame('de_DE', $selected[0]['locale']);
    }

    public function testResolveActiveLocalePrefersUserLanguage(): void
    {
        $renderer = $this->makeRenderer(
            ['interface' => ['language' => 'en_EN', 'enabled_languages' => 'en_EN,de_DE']],
            'de_DE'
        );

        $this->assertSame('de_DE', $renderer->resolveActiveLocale());
    }

    public function testResolveActiveLocaleFallsBackToConfig(): void
    {
        $renderer = $this->makeRenderer(
            ['interface' => ['language' => 'fr_FR', 'enabled_languages' => 'en_EN,fr_FR']]
        );

        $this->assertSame('fr_FR', $renderer->resolveActiveLocale());
    }

    public function testGetOverrideAppliesForEnabledLocale(): void
    {
        $renderer = $this->makeRenderer(
            ['interface' => ['language' => 'en_EN', 'enabled_languages' => 'en_EN,de_DE']]
        );

        $_GET['lang'] = 'de_DE';
        $this->assertSame('de_DE', $renderer->resolveActiveLocale());
    }

    public function testGetOverrideIgnoredForDisabledOrMalformedLocale(): void
    {
        $renderer = $this->makeRenderer(
            ['interface' => ['language' => 'en_EN', 'enabled_languages' => 'en_EN,de_DE']]
        );

        // Not in enabled_languages
        $_GET['lang'] = 'fr_FR';
        $this->assertSame('en_EN', $renderer->resolveActiveLocale());

        // Malformed values must never pass the character allowlist
        $_GET['lang'] = '../etc';
        $this->assertSame('en_EN', $renderer->resolveActiveLocale());

        $_GET['lang'] = ['de_DE'];
        $this->assertSame('en_EN', $renderer->resolveActiveLocale());
    }
}
