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

        $this->supportedLocales = ['en_US', 'fr_FR'];
        $localeDirectory = dirname('locales', 2);
        $this->localeManager = new LocaleManager($this->supportedLocales, $localeDirectory);
    }

    protected function tearDown(): void
    {
        // Restore the original error reporting settings
        error_reporting($this->originalErrorReporting);
        ini_set('error_log', $this->originalLogDestination);
    }

//    public function testSetsLocaleWhenSupportedLocaleProvided()
//    {
//        $this->localeManager->setLocale('fr_FR');
//        $this->assertEquals('fr_FR.UTF-8', setlocale(LC_ALL, 0));
//    }

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
        $this->localeManager->setLocale('en_EN');
        $this->assertNotEquals('en_EN', setlocale(LC_ALL, 0));
    }

    public function testDoesNotSetLocaleWhenLocaleIsEnENUTF8()
    {
        $this->localeManager->setLocale('en_EN.UTF-8');
        $this->assertNotEquals('en_EN.UTF-8', setlocale(LC_ALL, 0));
    }
}
