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

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

trait UserPasswordValidationTrait
{
    public function getPasswordValidationConstraints(): array
    {
        return [
            'pa_pass' => [
                new Assert\NotBlank(),
                new Assert\Length([
                    'min' => $this->config['password_policy']['min_length'],
                    'minMessage' => 'Poweradmin administrator password must be at least 6 characters long'
                ]),
                new Assert\Callback([$this, 'validateLoginPassword']),
            ],
        ];
    }

    public function validateLoginPassword($paPass, ExecutionContextInterface $context): void
    {
        $policy = $this->config['password_policy'];
        if ($policy['require_uppercase'] && !preg_match('/[A-Z]/', $paPass)) {
            $context->buildViolation('Password must contain at least one uppercase letter.')
                ->atPath('pa_pass')
                ->addViolation();
        }
        if ($policy['require_lowercase'] && !preg_match('/[a-z]/', $paPass)) {
            $context->buildViolation('Password must contain at least one lowercase letter.')
                ->atPath('pa_pass')
                ->addViolation();
        }
        if ($policy['require_numbers'] && !preg_match('/\d/', $paPass)) {
            $context->buildViolation('Password must contain at least one number.')
                ->atPath('pa_pass')
                ->addViolation();
        }
        if ($policy['require_special'] && !preg_match('/[' . preg_quote($policy['special_characters'], '/') . ']/', $paPass)) {
            $context->buildViolation('Password must contain at least one special character.')
                ->atPath('pa_pass')
                ->addViolation();
        }
    }
}
