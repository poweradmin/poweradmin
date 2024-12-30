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

use PDO;
use PoweradminInstall\LocaleHandler;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ConfiguringDatabaseValidator extends AbstractStepValidator
{
    public function validate(): array
    {
        $constraints = new Assert\Collection([
            'submit' => [
                new Assert\NotBlank(),
            ],
            'step' => [
                new Assert\NotBlank(),
            ],
            'language' => [
                new Assert\NotBlank(),
                new Assert\Choice(['choices' => LocaleHandler::getAvailableLanguages()]),
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
                new Assert\Callback([$this, 'validateDbName']),
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
        if (in_array($input['db_type'], ['mysql', 'pgsql'])) {
            if (empty($dbUser)) {
                $context->buildViolation('This value should not be blank.')
                    ->atPath('db_user')
                    ->addViolation();
            } else {
                $maxLength = $input['db_type'] === 'mysql' ? 32 : 63;

                if (mb_strlen($dbUser) > $maxLength) {
                    $context->buildViolation("This value is too long. It should have {$maxLength} characters or less.")
                        ->atPath('db_user')
                        ->addViolation();
                }

//                if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbUser)) {
//                    $context->buildViolation('This value contains invalid characters. Only letters, numbers, and underscores are allowed.')
//                        ->atPath('db_user')
//                        ->addViolation();
//                }
            }
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

    public function validateDbName($dbName, ExecutionContextInterface $context): void
    {
        $input = $context->getRoot();
        if ($input['db_type'] === 'sqlite') {
            if (!file_exists($dbName)) {
                $context->buildViolation('Database file does not exist')
                    ->addViolation();
                return;
            }

            $realPath = realpath($dbName);
            if ($realPath === false) {
                $context->buildViolation('Invalid database path')
                    ->addViolation();
                return;
            }
            $dbName = $realPath;

            if (str_contains($dbName, '../') || str_contains($dbName, '..\\')) {
                $context->buildViolation('Directory traversal is not allowed')
                    ->addViolation();
                return;
            }

            if (!preg_match('/\.(sqlite3?|db3?|sqlite-journal)$/', $dbName)) {
                $context->buildViolation('Database file must have a valid SQLite extension (.sqlite, .sqlite3, .db, .db3)')
                    ->addViolation();
                return;
            }

            if (file_exists($dbName)) {
                if (!is_readable($dbName)) {
                    $context->buildViolation('Database file is not readable')
                        ->addViolation();
                    return;
                }

                if (!is_writable($dbName)) {
                    $context->buildViolation('Database file is not writable')
                        ->addViolation();
                    return;
                }

                $pdo = new PDO("sqlite:{$dbName}");

                $stmt = $pdo->query('SELECT 1');
                if ($stmt === false) {
                    $context->buildViolation('Failed to query database')
                        ->addViolation();
                    return;
                }

                $version = $pdo->query('SELECT sqlite_version()')->fetchColumn();
                if (version_compare($version, '3.0.0', '<')) {
                    $context->buildViolation('Unsupported SQLite version')
                        ->addViolation();
                    return;
                }

                $pdo = null;
            }
        }
    }
}
