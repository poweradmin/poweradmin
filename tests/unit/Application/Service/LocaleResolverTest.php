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

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\LocaleResolver;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Covers the shared locale precedence (GET lang override > session user
 * language > interface.language) and enabled-locale list parsing.
 */
class LocaleResolverTest extends TestCase
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

    private function makeResolver(array $interfaceConfig, ?string $userLanguage = null): LocaleResolver
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            function (string $group, string $key, $default = null) use ($interfaceConfig) {
                return $interfaceConfig[$group][$key] ?? $default;
            }
        );

        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getUserLanguage')->willReturn($userLanguage);

        // Request snapshots the superglobals, so it must be built after the
        // test has populated $_GET
        return new LocaleResolver($config, $userContext, new Request());
    }

    public function testFallsBackToConfiguredLanguage(): void
    {
        $resolver = $this->makeResolver(
            ['interface' => ['language' => 'fr_FR', 'enabled_languages' => 'en_EN,fr_FR']]
        );

        $this->assertSame('fr_FR', $resolver->resolve());
    }

    public function testFallsBackToDefaultWithoutAnyConfiguration(): void
    {
        $this->assertSame('en_EN', $this->makeResolver([])->resolve());
    }

    public function testUserLanguageTakesPrecedenceOverConfig(): void
    {
        $resolver = $this->makeResolver(
            ['interface' => ['language' => 'en_EN', 'enabled_languages' => 'en_EN,de_DE']],
            'de_DE'
        );

        $this->assertSame('de_DE', $resolver->resolve());
    }

    public function testGetOverrideTakesPrecedenceOverUserLanguage(): void
    {
        $_GET['lang'] = 'fr_FR';
        $resolver = $this->makeResolver(
            ['interface' => ['language' => 'en_EN', 'enabled_languages' => 'en_EN,de_DE,fr_FR']],
            'de_DE'
        );

        $this->assertSame('fr_FR', $resolver->resolve());
    }

    public function testGetOverrideIgnoredWhenNotEnabled(): void
    {
        $_GET['lang'] = 'fr_FR';
        $resolver = $this->makeResolver(
            ['interface' => ['language' => 'en_EN', 'enabled_languages' => 'en_EN,de_DE']]
        );

        $this->assertSame('en_EN', $resolver->resolve());
    }

    public function testGetOverrideIgnoredForMalformedValues(): void
    {
        $config = ['interface' => ['language' => 'en_EN', 'enabled_languages' => 'en_EN,de_DE']];

        // Malformed values must never pass the character allowlist
        $_GET['lang'] = '../etc';
        $this->assertSame('en_EN', $this->makeResolver($config)->resolve());

        $_GET['lang'] = ['de_DE'];
        $this->assertSame('en_EN', $this->makeResolver($config)->resolve());

        $_GET['lang'] = '';
        $this->assertSame('en_EN', $this->makeResolver($config)->resolve());
    }

    public function testGetOverrideAcceptedFromSpacedEnabledList(): void
    {
        // Spaced lists previously worked in the page chrome but not in the
        // translator chain; the shared resolver trims everywhere
        $_GET['lang'] = 'de_DE';
        $resolver = $this->makeResolver(
            ['interface' => ['language' => 'en_EN', 'enabled_languages' => 'en_EN, de_DE']]
        );

        $this->assertSame('de_DE', $resolver->resolve());
    }

    public function testSupportedLocalesAreTrimmedAndFiltered(): void
    {
        $resolver = $this->makeResolver(
            ['interface' => ['enabled_languages' => ' en_EN, de_DE ,, fr_FR ']]
        );

        $this->assertSame(['en_EN', 'de_DE', 'fr_FR'], $resolver->getSupportedLocales());
    }

    public function testEmptyEnabledLanguagesFallsBackToDefault(): void
    {
        $this->assertSame(
            ['en_EN'],
            $this->makeResolver(['interface' => ['enabled_languages' => '']])->getSupportedLocales()
        );

        $this->assertSame(
            ['en_EN'],
            $this->makeResolver([])->getSupportedLocales()
        );

        $this->assertSame(
            ['en_EN'],
            $this->makeResolver(['interface' => ['enabled_languages' => ' , ']])->getSupportedLocales()
        );
    }
}
