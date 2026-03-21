<?php

namespace unit\Api;

/**
 * Mirrors the input helper methods from AbstractApiController for unit testing.
 * Returns default when key is absent; returns null when key is present but invalid type.
 */
class TestableApiInputHelper
{
    public function callInputString(array $input, string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }
        return is_string($input[$key]) ? $input[$key] : null;
    }

    public function callInputInt(array $input, string $key, ?int $default = null): ?int
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }
        $value = $input[$key];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int)$value;
        }
        return null;
    }

    public function callInputBool(array $input, string $key, ?bool $default = null): ?bool
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }
        $value = $input[$key];
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }
        return null;
    }

    public function callInputIntFromBool(array $input, string $key, ?int $default = 0): ?int
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }
        $value = $input[$key];
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value)) {
            return $value;
        }
        if ($value === 'true' || $value === 'false') {
            return $value === 'true' ? 1 : 0;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int)$value;
        }
        return null;
    }

    /**
     * Mirrors the template extraction pattern from ZonesController.
     */
    public function callInputTemplate(array $input): string
    {
        $raw = $input['template'] ?? 'none';
        if (is_int($raw)) {
            return (string)$raw;
        }
        return is_string($raw) ? $raw : 'none';
    }
}
