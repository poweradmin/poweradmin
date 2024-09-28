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

namespace PoweradminInstall\Validators;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ConfiguringDatabaseValidator implements StepValidatorInterface
{

    private Request $request;
    private ValidatorInterface $validator;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->validator = Validation::createValidator();
    }

    public function validate(Request $request): array
    {
        $constraints = new Assert\Collection([
            'step' => [
                new Assert\NotBlank(),
            ],
            'language' => [
                new Assert\NotBlank(),
            ],
            'db_type' => [
                new Assert\NotBlank(),
                new Assert\Choice(['choices' => ['mysql', 'pgsql', 'sqlite']]),
            ],
            'db_user' => [
                new Assert\Optional(),
                new Assert\Callback([$this, 'validateDbUser']),
            ],
            'db_pass' => [
                new Assert\Optional(),
                new Assert\Callback([$this, 'validateDbPass']),
            ],
            'db_host' => [
                new Assert\Optional(),
                new Assert\Callback([$this, 'validateDbHost']),
            ],
            'db_port' => [
                new Assert\Optional(),
            ],
            'db_name' => [
                new Assert\NotBlank(),
            ],
            'db_charset' => [
                new Assert\Optional(),
            ],
            'db_collation' => [
                new Assert\Optional(),
            ],
            'pa_pass' => [
                new Assert\NotBlank(),
            ],
        ]);

        $input = $this->request->request->all();
        $violations = $this->validator->validate($input, $constraints);

        return ValidationErrorHelper::formatErrors($violations);
    }

    public function validateDbUser($dbUser, ExecutionContextInterface $context): void
    {
        $input = $context->getRoot();
        if (in_array($input['db_type'], ['mysql', 'pgsql']) && empty($dbUser)) {
            $context->buildViolation('This value should not be blank.')
                ->atPath('db_user')
                ->addViolation();
        }
    }

    public function validateDbPass($dbPass, ExecutionContextInterface $context): void
    {
        $input = $context->getRoot();
        if (in_array($input['db_type'], ['mysql', 'pgsql']) && empty($dbPass)) {
            $context->buildViolation('This value should not be blank.')
                ->atPath('db_pass')
                ->addViolation();
        }
    }

    public function validateDbHost($dbHost, ExecutionContextInterface $context): void
    {
        $input = $context->getRoot();
        if (in_array($input['db_type'], ['mysql', 'pgsql']) && empty($dbHost)) {
            $context->buildViolation('This value should not be blank.')
                ->atPath('db_host')
                ->addViolation();
        }
    }
}
