<?php

namespace Poweradmin\Domain\Locale;

class Locale {
    private string $locale;
    private string $language;

    public function __construct(string $locale, string $language) {
        $this->locale = $locale;
        $this->language = $language;
    }

    public function isSelected(string $interfaceLanguage): bool {
        return substr($this->locale, 0, 2) == substr($interfaceLanguage, 0, 2);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): void
    {
        $this->language = $language;
    }
}
