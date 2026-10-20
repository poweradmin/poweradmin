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

namespace Poweradmin\Domain\Service\Zone;

/**
 * A user or group that a change would take past its zone limit.
 */
final readonly class ZoneLimitBreach
{
    public const SUBJECT_USER = 'user';
    public const SUBJECT_GROUP = 'group';

    /**
     * @param string $subject One of the SUBJECT_* constants
     * @param string $name Username or group name
     * @param int $owned Zones the user or group owns now
     * @param int $limit Its zone limit
     */
    public function __construct(
        public string $subject,
        public string $name,
        public int $owned,
        public int $limit
    ) {
    }

    /**
     * The web wording, for results that carry a translated message.
     */
    public function localizedMessage(): string
    {
        $format = $this->subject === self::SUBJECT_GROUP
            ? _('Zone limit reached: group %1$s owns %2$d of %3$d zones.')
            : _('Zone limit reached: %1$s owns %2$d of %3$d zones.');

        return sprintf($format, $this->name, $this->owned, $this->limit);
    }

    /**
     * The API wording, plain English as every API error string is.
     */
    public function message(): string
    {
        return sprintf(
            'Zone limit reached: %s %s owns %d of %d zones.',
            $this->subject,
            $this->name,
            $this->owned,
            $this->limit
        );
    }
}
