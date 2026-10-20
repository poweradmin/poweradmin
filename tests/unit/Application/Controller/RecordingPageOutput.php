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
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Unit\Application\Controller;

use Poweradmin\Application\Web\PageOutputInterface;

/**
 * Records what a controller renders instead of producing a page: each
 * template with its parameters as the controller built them, the number of
 * error pages (chrome without a body), and the messages every page carried.
 */
final class RecordingPageOutput implements PageOutputInterface
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $rendered = [];

    public int $errorPages = 0;

    /**
     * The messages the pages showed, keyed by the script they were flashed for
     * ('system' for the ones every page shows). Rendering consumes them from the
     * session, so this is where a test finds them afterwards.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    public array $messages = [];

    public function renderPage(string $template, array $params, array $requestData, string $pageTitle, ?array $systemMessages, ?array $scriptMessages): void
    {
        $this->rendered[] = [$template, $params];
        $this->keep('system', $systemMessages);
        $this->keep(pathinfo($template)['filename'], $scriptMessages);
    }

    public function renderChrome(array $requestData, string $pageTitle, ?array $systemMessages): void
    {
        $this->errorPages++;
        $this->keep('system', $systemMessages);
    }

    /** @param list<array<string, mixed>>|null $messages */
    private function keep(string $script, ?array $messages): void
    {
        foreach ($messages ?? [] as $message) {
            $this->messages[$script][] = $message;
        }
    }

    public function renderedTemplate(): ?string
    {
        return $this->rendered[0][0] ?? null;
    }

    /** @return array<string, mixed> */
    public function renderedParams(): array
    {
        return $this->rendered[0][1] ?? [];
    }
}
