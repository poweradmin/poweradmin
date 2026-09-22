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

namespace Poweradmin\Tests\Unit\Application\Web;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Boot\AppManager;
use Poweradmin\Application\Web\PageRenderer;
use Poweradmin\Application\Service\Auth\CsrfTokenService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Application\Module\ModuleRegistry;
use ReflectionClass;
use TestHelpers\FakeConfiguration;

/**
 * Covers the language-selector and branding-asset logic of PageRenderer.
 * Locale precedence itself is resolved by LocaleResolver (see
 * LocaleResolverTest) and shared through AppManager.
 */
class PageRendererTest extends TestCase
{
    private function makeRenderer(
        array $supportedLocales,
        string $interfaceLocale = 'en_EN',
        array $interfaceConfig = [],
        ?ModuleRegistry $moduleRegistry = null,
        ?Closure $hasPermission = null
    ): PageRenderer {
        $app = $this->createMock(AppManager::class);
        $app->method('getSupportedLocales')->willReturn($supportedLocales);
        $app->method('getInterfaceLocale')->willReturn($interfaceLocale);

        $config = new FakeConfiguration(['interface' => $interfaceConfig]);

        return new PageRenderer(
            $app,
            $config,
            $this->createMock(CsrfTokenService::class),
            $this->createMock(UserContextService::class),
            $moduleRegistry ?? $this->createMock(ModuleRegistry::class),
            false,
            $hasPermission ?? fn(string $permission): bool => false,
            fn(): ?array => null,
            fn() => [],
            false
        );
    }

    /**
     * A page is the header, the body and the footer in that order; the body gets
     * the language selector vars and the falsy chrome defaults under the
     * controller's own parameters.
     */
    public function testRenderPageWrapsTheBodyInTheChromeAndFillsTheSharedDefaults(): void
    {
        $app = $this->createMock(AppManager::class);
        $app->method('getSupportedLocales')->willReturn(['en_EN', 'de_DE']);
        $app->method('getInterfaceLocale')->willReturn('de_DE');
        $app->method('getThemeBasePath')->willReturn('templates');
        $app->method('getThemeName')->willReturn('default');
        $renderedTemplates = [];
        $bodyParams = null;
        $app->method('render')->willReturnCallback(function (string $template, array $params = []) use (&$renderedTemplates, &$bodyParams): void {
            $renderedTemplates[] = $template;
            if ($template === 'list_forward_zones.html') {
                $bodyParams = $params;
            }
        });
        $config = new FakeConfiguration();

        $renderer = new PageRenderer(
            $app,
            $config,
            $this->createMock(CsrfTokenService::class),
            $this->createMock(UserContextService::class),
            $this->createMock(ModuleRegistry::class),
            false,
            fn(string $permission): bool => false,
            fn(): ?array => null,
            fn() => [],
            false
        );

        ob_start();
        $renderer->renderPage('list_forward_zones.html', ['zones' => [], 'message' => 'kept'], ['page' => 'list_forward_zones'], 'Zones', null, null);
        ob_end_clean();

        $this->assertSame(['header.html', 'list_forward_zones.html', 'footer.html'], $renderedTemplates);
        $this->assertSame([], $bodyParams['zones']);
        $this->assertSame('kept', $bodyParams['message'], 'a page parameter wins over the shared default');
        $this->assertFalse($bodyParams['is_reverse_zone']);
        $this->assertFalse($bodyParams['success']);
        $this->assertSame('de_DE', $bodyParams['current_language']);
        $this->assertTrue($bodyParams['show_language_selector']);
    }

    public function testModuleNavItemsComeFromTheInjectedRegistryFilteredByPermission(): void
    {
        $registry = $this->createMock(ModuleRegistry::class);
        $registry->expects($this->once())
            ->method('getNavItems')
            ->with(true)
            ->willReturn([
                ['label' => 'Open', 'url' => '/open'],
                ['label' => 'Allowed', 'url' => '/allowed', 'permission' => 'zone_content_view_own'],
                ['label' => 'Hidden', 'url' => '/hidden', 'permission' => 'user_is_ueberuser_only'],
            ]);
        $granted = ['user_is_ueberuser', 'zone_content_view_own'];
        $renderer = $this->makeRenderer(['en_EN'], 'en_EN', [], $registry, fn(string $p): bool => in_array($p, $granted, true));

        $items = (new ReflectionClass(PageRenderer::class))->getMethod('getModuleNavItems')->invoke($renderer);

        $this->assertSame(['Open', 'Allowed'], array_column($items, 'label'));
    }

    private function brandingUrl(string $configured, string $baseUrlPrefix): string
    {
        $renderer = $this->makeRenderer(['en_EN'], 'en_EN', ['favicon_path' => $configured]);

        return (new ReflectionClass(PageRenderer::class))
            ->getMethod('brandingUrl')
            ->invoke($renderer, 'favicon_path', '/favicon.ico', $baseUrlPrefix);
    }

    public function testSingleEnabledLanguageHidesSelector(): void
    {
        $renderer = $this->makeRenderer(['en_EN']);

        $vars = $renderer->getLanguageSelectorVars('en_EN');

        $this->assertSame('en_EN', $vars['current_language']);
        $this->assertArrayNotHasKey('locales', $vars);
        $this->assertArrayNotHasKey('show_language_selector', $vars);
    }

    public function testMultipleLanguagesBuildSortedSelector(): void
    {
        $renderer = $this->makeRenderer(['en_EN', 'de_DE', 'fr_FR']);

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

    public function testActiveLocaleAndSelectorComeFromAppManager(): void
    {
        $renderer = $this->makeRenderer(['en_EN', 'de_DE'], 'de_DE');

        $this->assertSame('de_DE', $renderer->resolveActiveLocale());
        $this->assertSame('de_DE', $renderer->languageVars()['current_language']);
    }

    public static function brandingUrlProvider(): array
    {
        return [
            'unset falls back to the bundled asset' => ['', '', '/favicon.ico'],
            'unset keeps the subfolder prefix' => ['', '/poweradmin', '/poweradmin/favicon.ico'],
            'absolute url is used as-is' => ['https://cdn.example.com/brand.ico', '', 'https://cdn.example.com/brand.ico'],
            'absolute url ignores the prefix' => ['https://cdn.example.com/brand.ico', '/poweradmin', 'https://cdn.example.com/brand.ico'],
            'protocol-relative url is used as-is' => ['//cdn.example.com/brand.ico', '', '//cdn.example.com/brand.ico'],
            'protocol-relative url ignores the prefix' => ['//cdn.example.com/brand.ico', '/poweradmin', '//cdn.example.com/brand.ico'],
            'relative path gains a leading slash' => ['custom/brand.ico', '', '/custom/brand.ico'],
            'relative path gains the prefix' => ['custom/brand.ico', '/poweradmin', '/poweradmin/custom/brand.ico'],
            'site-absolute path is left alone' => ['/custom/brand.ico', '', '/custom/brand.ico'],
            'site-absolute path is served from the subfolder' => ['/custom/brand.ico', '/poweradmin', '/poweradmin/custom/brand.ico'],
        ];
    }

    #[DataProvider('brandingUrlProvider')]
    public function testBrandingUrlResolvesConfiguredAssets(string $configured, string $baseUrlPrefix, string $expected): void
    {
        $this->assertSame($expected, $this->brandingUrl($configured, $baseUrlPrefix));
    }
}
