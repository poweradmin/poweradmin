<?php

namespace Poweradmin\Tests\Unit\Api;

use Poweradmin\Tests\Unit\Application\Controller\Api\TestableAbstractApiHelpersController;
use ReflectionClass;

/**
 * Exposes the input helper methods of AbstractApiController for unit testing.
 * Returns default when key is absent; returns null when key is present but invalid type.
 */
class TestableApiInputHelper
{
    private TestableAbstractApiHelpersController $controller;

    public function __construct()
    {
        // The controller constructor authenticates, so bypass it: the input helpers are pure
        $this->controller = (new ReflectionClass(TestableAbstractApiHelpersController::class))
            ->newInstanceWithoutConstructor();
    }

    public function callInputString(array $input, string $key, ?string $default = null): ?string
    {
        return $this->controller->callInputString($input, $key, $default);
    }

    public function callInputInt(array $input, string $key, ?int $default = null): ?int
    {
        return $this->controller->callInputInt($input, $key, $default);
    }

    public function callInputBool(array $input, string $key, ?bool $default = null): ?bool
    {
        return $this->controller->callInputBool($input, $key, $default);
    }

    public function callInputIntFromBool(array $input, string $key, ?int $default = 0): ?int
    {
        return $this->controller->callInputIntFromBool($input, $key, $default);
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
