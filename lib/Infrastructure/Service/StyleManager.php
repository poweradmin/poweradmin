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

class StyleManager
{
    private string $themeBasePath;
    private string $themeName;
    private const DEFAULT_STYLE = 'light';

    private string $selectedStyle;
    private array $availableStyles;
    private string $styleDir;

    public function __construct(
        string $style = self::DEFAULT_STYLE,
        string $themeBasePath = 'templates',
        string $themeName = 'default'
    ) {
        $this->themeBasePath = $themeBasePath;
        $this->themeName = $themeName;
        $this->styleDir = $themeBasePath . '/' . $themeName . '/style';
        $this->availableStyles = $this->getAvailableStyles();

        $styleFromCookie = $this->getStyleFromCookie();

        if ($styleFromCookie && $this->isStyleAvailable($styleFromCookie)) {
            $this->selectedStyle = $styleFromCookie;
        } elseif ($style && $this->isStyleAvailable($style)) {
            $this->selectedStyle = $style;
        } else {
            $this->selectedStyle = self::DEFAULT_STYLE;
        }
    }

    private function getAvailableStyles(): array
    {
        $styles = [];
        if (!is_dir($this->styleDir)) {
            return $styles;
        }

        $dir = new DirectoryIterator($this->styleDir);

        foreach ($dir as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'css') {
                $styles[] = pathinfo($fileInfo->getFilename(), PATHINFO_FILENAME);
            }
        }

        return $styles;
    }

    private function isStyleAvailable(string $style): bool
    {
        return in_array($style, $this->availableStyles);
    }

    public function getSelectedStyle(): string
    {
        return $this->selectedStyle;
    }

    private function getStyleFromCookie(): ?string
    {
        if (isset($_COOKIE['style']) && in_array($_COOKIE['style'], ['light', 'dark'])) {
            return $_COOKIE['style'];
        }
        return null;
    }
}
