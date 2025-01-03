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

namespace PoweradminInstall\Validators;

use Poweradmin\Application\Service\CsrfTokenService;
use PoweradminInstall\LocaleHandler;
use Symfony\Component\Validator\Constraints as Assert;

abstract class BaseValidator extends AbstractStepValidator
{
    protected function getCsrfConstraints(): array
    {
        if (!($this->config['csrf']['enabled'] ?? true)) {
            return [
                'install_token' => [
                    new Assert\Optional(),
                ]
            ];
        }

        return [
            'install_token' => [
                new Assert\NotBlank(),
                new Assert\Length([
                    'min' => CsrfTokenService::TOKEN_LENGTH,
                    'max' => CsrfTokenService::TOKEN_LENGTH
                ]),
            ]
        ];
    }

    protected function getBaseConstraints(): array
    {
        return array_merge([
            'submit' => [
                new Assert\NotBlank(),
            ],
            'language' => [
                new Assert\NotBlank(),
                new Assert\Choice(['choices' => LocaleHandler::getAvailableLanguages()]),
            ]
        ], $this->getCsrfConstraints());
    }
}
