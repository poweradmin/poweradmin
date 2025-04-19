<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\LocaleManager;

class LocaleManagerTest extends TestCase
{
    private $originalErrorReporting;
    private $originalLogDestination;
    private array $supportedLocales;
    private LocaleManager $localeManager;

    protected function setUp(): void
    {
        $this->originalErrorReporting = error_reporting();
        $this->originalLogDestination = ini_get('error_log');
        error_reporting(0);
        ini_set('error_log', '/dev/null');

        $this->supportedLocales = ['en_US', 'fr_FR', 'en_EN'];
        $localeDirectory = dirname('locales', 2);
        $this->localeManager = new LocaleManager($this->supportedLocales, $localeDirectory);
    }

    protected function tearDown(): void
    {
        // Restore the original error reporting settings
        error_reporting($this->originalErrorReporting);
        ini_set('error_log', $this->originalLogDestination);
    }

    public function testSetsLocaleWhenSupportedLocaleProvided()
    {
        // Save original locale
        $originalLocale = setlocale(LC_ALL, 0);

        // Use fr_FR since en_EN has special handling in LocaleManager
        $this->localeManager->setLocale('fr_FR');

        // Just check that locale was changed in some way, as the exact format varies by system
        $this->assertStringContainsString('fr_FR', setlocale(LC_ALL, 0));

        // Restore original locale
        setlocale(LC_ALL, $originalLocale);
    }

    public function testDoesNotSetLocaleWhenUnsupportedLocaleProvided()
    {
        $this->localeManager->setLocale('es_ES');
        $this->assertNotEquals('es_ES', setlocale(LC_ALL, 0));
    }

    public function testDoesNotSetLocaleWhenLocaleDirectoryIsNotReadable()
    {
        $localeManager = new LocaleManager($this->supportedLocales, '/invalid/path');
        $localeManager->setLocale('fr_FR');
        $this->assertNotEquals('fr_FR', setlocale(LC_ALL, 0));
    }

    public function testDoesNotSetLocaleWhenLocaleIsEnEN()
    {
        // Save original locale
        $originalLocale = setlocale(LC_ALL, 0);

        // Try to set the locale to en_EN, which has special handling
        $this->localeManager->setLocale('en_EN');

        // Should still be the original locale since en_EN should be skipped
        $this->assertEquals($originalLocale, setlocale(LC_ALL, 0));
    }

    public function testDoesNotSetLocaleWhenLocaleIsEnENUTF8()
    {
        // Save original locale
        $originalLocale = setlocale(LC_ALL, 0);

        // Try to set the locale to en_EN.UTF-8, which has special handling
        $this->localeManager->setLocale('en_EN.UTF-8');

        // Should still be the original locale since en_EN.UTF-8 should be skipped
        $this->assertEquals($originalLocale, setlocale(LC_ALL, 0));
    }
}
