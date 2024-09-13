<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
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

namespace PoweradminInstall;

class StepValidator
{

    private const MIN_STEP_VALUE = InstallationSteps::STEP_CHOOSE_LANGUAGE;
    private const MAX_STEP_VALUE = InstallationSteps::STEP_INSTALLATION_COMPLETE;

    public function getCurrentStep(array $postData): int
    {
        $sanitizedData = filter_var_array($postData, [
            'step' => [
                'filter' => FILTER_VALIDATE_INT,
                'options' => ['default' => InstallationSteps::STEP_CHOOSE_LANGUAGE]
            ]
        ]);

        $step = $sanitizedData['step'];

        if ($step < self::MIN_STEP_VALUE || $step > self::MAX_STEP_VALUE) {
            return InstallationSteps::STEP_CHOOSE_LANGUAGE;
        }

        return ($step !== false && $step !== null) ? $step : InstallationSteps::STEP_CHOOSE_LANGUAGE;
    }
}
