<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Infrastructure\Service;

use DirectoryIterator;

class ThemeManager
{
    private const DEFAULT_STYLE_DIR = __DIR__ . '/../../../style/';
    private const DEFAULT_THEME = 'ignite';

    private string $selectedTheme;
    private array $availableThemes;
    private string $styleDir;

    public function __construct(string $style = self::DEFAULT_THEME, string $style_dir = self::DEFAULT_STYLE_DIR)
    {
        $this->styleDir = $style_dir;
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

