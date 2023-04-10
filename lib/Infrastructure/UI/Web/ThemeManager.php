<?php

namespace Poweradmin\Infrastructure\UI\Web;

use DirectoryIterator;

class ThemeManager
{
    private const DEFAULT_STYLE_DIR = __DIR__ . '/../../../../style/';
    private const DEFAULT_THEME = 'ignite';

    private string $selectedTheme;
    private array $availableThemes;
    private string $styleDir;

    public function __construct(string $style = null, string $style_dir = null)
    {
        $this->styleDir = $style_dir ?? self::DEFAULT_STYLE_DIR;
        $this->availableThemes = $this->getAvailableThemes();

        $themeFromCookie = $this->getThemeFromCookie();

        if ($themeFromCookie && $this->isThemeAvailable($themeFromCookie)) {
            $this->selectedTheme = $themeFromCookie;
        } elseif ($style && $this->isThemeAvailable($style)) {
            $this->selectedTheme = $style;
        } else {
            $this->selectedTheme = self::DEFAULT_THEME;
        }
    }

    private function getAvailableThemes(): array
    {
        $themes = [];
        if (!is_dir($this->styleDir)) {
            return $themes;
        }

        $dir = new DirectoryIterator($this->styleDir);

        foreach ($dir as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'css') {
                $themes[] = pathinfo($fileInfo->getFilename(), PATHINFO_FILENAME);
            }
        }

        return $themes;
    }

    private function isThemeAvailable(string $style): bool
    {
        return in_array($style, $this->availableThemes);
    }

    public function getSelectedTheme(): string
    {
        return $this->selectedTheme;
    }

    private function getThemeFromCookie(): ?string
    {
        if (isset($_COOKIE['theme']) && in_array($_COOKIE['theme'], ['ignite', 'spark'])) {
            return $_COOKIE['theme'];
        }
        return null;
    }
}

