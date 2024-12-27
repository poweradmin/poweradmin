<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\LocaleManager;

class LocaleManagerTest extends TestCase
{
    private array $supportedLocales;
    private LocaleManager $localeManager;

    protected function setUp(): void
    {
        $this->supportedLocales = ['en_US', 'fr_FR'];
        $localeDirectory = dirname(dirname('locales'));
        $this->localeManager = new LocaleManager($this->supportedLocales, $localeDirectory);
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
