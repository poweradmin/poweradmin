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

namespace Poweradmin\Application\Console;

use InvalidArgumentException;

/**
 * The parsed command line: one command word, "--name=value" options and the
 * remaining positional words. Options may appear before or after the command.
 */
final class Arguments
{
    private ?string $command;

    /** @var array<string, string|true> */
    private array $options;

    /** @var list<string> */
    private array $positionals;

    /**
     * @param array<string, string|true> $options
     * @param list<string> $positionals
     */
    private function __construct(?string $command, array $options, array $positionals)
    {
        $this->command = $command;
        $this->options = $options;
        $this->positionals = $positionals;
    }

    /**
     * @param list<string> $argv The arguments without the script name
     * @throws InvalidArgumentException on a short or malformed option
     */
    public static function parse(array $argv): self
    {
        $command = null;
        $options = [];
        $positionals = [];
        $optionsEnded = false;

        foreach ($argv as $arg) {
            if ($optionsEnded || $arg === '-' || !str_starts_with($arg, '-')) {
                if ($command === null) {
                    $command = $arg;
                } else {
                    $positionals[] = $arg;
                }
                continue;
            }

            if ($arg === '--') {
                $optionsEnded = true;
                continue;
            }

            if ($arg === '-h') {
                $options['help'] = true;
                continue;
            }

            if (!str_starts_with($arg, '--')) {
                throw new InvalidArgumentException(sprintf('Unknown option "%s"', $arg));
            }

            [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
                throw new InvalidArgumentException(sprintf('Malformed option "%s"', $arg));
            }

            $options[$name] = $value ?? true;
        }

        return new self($command, $options, $positionals);
    }

    public function command(): ?string
    {
        return $this->command;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    /** The option's value; null when absent or given without "=value" */
    public function value(string $name): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * A positive integer option, null when absent.
     *
     * @throws InvalidArgumentException when present but not a positive integer
     */
    public function positiveInt(string $name): ?int
    {
        if (!$this->has($name)) {
            return null;
        }

        $value = $this->value($name);
        if ($value === null || !ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException(sprintf('Option --%s expects a positive integer', $name));
        }

        return (int) $value;
    }

    /**
     * Option names that are set but not in the allowed list.
     *
     * @param list<string> $allowed
     * @return list<string>
     */
    public function unknownOptions(array $allowed): array
    {
        return array_values(array_diff(array_keys($this->options), $allowed));
    }

    /** @return list<string> */
    public function positionals(): array
    {
        return $this->positionals;
    }
}
