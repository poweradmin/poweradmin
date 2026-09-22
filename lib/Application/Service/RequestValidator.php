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

namespace Poweradmin\Application\Service;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validates controller request data against Symfony constraints.
 *
 * Holds the constraint set for the request so controllers can declare
 * rules once and validate repeatedly; extra and missing fields are
 * tolerated to match the permissive form handling of the web UI.
 */
final class RequestValidator
{
    private ValidatorInterface $validator;
    private array $constraints = [];

    public function __construct()
    {
        $this->validator = Validation::createValidator();
    }

    /**
     * Sets validation constraints for the request data.
     *
     * @param array $constraints The validation constraints.
     */
    public function setConstraints(array $constraints): void
    {
        $this->constraints = $constraints;
    }

    /**
     * Validates data and returns constraint violations.
     *
     * @param array $data The data to validate.
     * @return ConstraintViolationListInterface
     */
    public function validate(array $data): ConstraintViolationListInterface
    {
        // An empty field counts as absent so optional type checks skip it (a blank record name
        // is the apex). Fields declared with Assert\Required keep their '' and fail NotBlank.
        foreach ($data as $key => $value) {
            if ($value === '' && !$this->requiresValue($key)) {
                unset($data[$key]);
            }
        }
        // Collection skips absent keys even for Required, so a missing field fails its NotBlank rule
        foreach ($this->constraints as $field => $constraint) {
            if ($constraint instanceof Assert\Required && !array_key_exists($field, $data)) {
                $data[$field] = '';
            }
        }

        $collectionConstraint = new Assert\Collection([
            'fields' => $this->constraints,
            'allowExtraFields' => true,
            'allowMissingFields' => true
        ]);

        return $this->validator->validate($data, $collectionConstraint);
    }

    private function requiresValue(string $field): bool
    {
        return ($this->constraints[$field] ?? null) instanceof Assert\Required;
    }

    /**
     * Message of the first violation, or null when the data is valid.
     */
    public function firstErrorMessage(array $data): ?string
    {
        $violations = $this->validate($data);

        if ($violations->count() > 0) {
            return (string) $violations->get(0)->getMessage();
        }
        return null;
    }
}
