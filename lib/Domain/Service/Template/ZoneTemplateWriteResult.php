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

namespace Poweradmin\Domain\Service\Template;

use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * Outcome of a zone template write. Callers read the reason and the refusal
 * from here instead of the session; a success may still carry a warning.
 */
final readonly class ZoneTemplateWriteResult
{
    private function __construct(
        public bool $success,
        public ?string $message,
        public ?Refusal $refusal
    ) {
    }

    public static function ok(?string $warning = null): self
    {
        return new self(true, $warning, null);
    }

    public static function failure(string $message, Refusal $refusal = Refusal::INVALID_INPUT): self
    {
        return new self(false, $message, $refusal);
    }

    public static function forbidden(string $message): self
    {
        return new self(false, $message, Refusal::FORBIDDEN);
    }

    public static function backendFailure(string $message): self
    {
        return new self(false, $message, Refusal::BACKEND_FAILURE);
    }
}
