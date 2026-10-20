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

namespace Poweradmin\Tests\Unit\Api\V2;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\PublicApiController;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

/**
 * PublicApiController::acceptsZoneRestrictedKey() defaults to true, so a v2 endpoint
 * that is not tied to a zone would accept a zone-restricted API key unless it says
 * otherwise. Every v2 controller must either refuse such keys or apply the key's zone
 * scope to what it reads and writes.
 */
class ZoneRestrictedKeyGuardTest extends TestCase
{
    private const CONTROLLER_DIR = __DIR__ . '/../../../../lib/Application/Controller/Api/V2';
    private const CONTROLLER_NAMESPACE = 'Poweradmin\\Application\\Controller\\Api\\V2\\';

    /** Calls that apply the API key's zone scope (ApiKeyScope and PublicApiController). */
    private const ZONE_SCOPE_CALLS = [
        'enforceApiKeyZoneScope',
        'isZoneAllowed',
        'getZoneIds',
        'hasZoneRestriction',
    ];

    public function testEveryV2ControllerRefusesZoneRestrictedKeysOrAppliesZoneScope(): void
    {
        $controllers = $this->v2Controllers();
        $this->assertNotEmpty($controllers, 'No v2 controllers found under ' . self::CONTROLLER_DIR);

        $unguarded = [];
        foreach ($controllers as $class => $file) {
            if ($this->refusesZoneRestrictedKeys($class) || $this->callsZoneScopeHelper($file)) {
                continue;
            }
            $unguarded[] = $class;
        }

        $this->assertSame(
            [],
            $unguarded,
            'These v2 controllers accept zone-restricted API keys without applying the zone scope. '
            . 'Override acceptsZoneRestrictedKey() to return false, or check every zone with '
            . 'enforceApiKeyZoneScope() / ApiKeyScope::isZoneAllowed().'
        );
    }

    /**
     * @return array<string, string> class name => source file, for every concrete PublicApiController
     */
    private function v2Controllers(): array
    {
        $dir = realpath(self::CONTROLLER_DIR);
        $this->assertNotFalse($dir, 'Missing ' . self::CONTROLLER_DIR);

        $controllers = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($dir) + 1, -4);
            $class = self::CONTROLLER_NAMESPACE . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(PublicApiController::class)) {
                continue;
            }
            $controllers[$class] = $file->getPathname();
        }
        ksort($controllers);

        return $controllers;
    }

    /**
     * @param class-string<PublicApiController> $class
     */
    private function refusesZoneRestrictedKeys(string $class): bool
    {
        $method = new ReflectionMethod($class, 'acceptsZoneRestrictedKey');
        if ($method->getDeclaringClass()->getName() === PublicApiController::class) {
            return false;
        }

        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        return $method->invoke($controller) === false;
    }

    /**
     * Looks for a method call on code tokens only, so a helper named in a comment or
     * a same-named method declaration does not count.
     */
    private function callsZoneScopeHelper(string $file): bool
    {
        $tokens = array_values(array_filter(
            token_get_all((string)file_get_contents($file)),
            fn($token) => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $callOperators = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], self::ZONE_SCOPE_CALLS, true)) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            if (is_array($previous) && in_array($previous[0], $callOperators, true) && $next === '(') {
                return true;
            }
        }

        return false;
    }
}
