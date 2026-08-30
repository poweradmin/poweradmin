<?php

namespace Poweradmin\Tests\Unit;

use OpenApi\Generator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Loader\YamlFileLoader;

/**
 * Every routed v2 endpoint must appear in the generated OpenAPI spec.
 *
 * Verbs are routed and implemented long before anyone remembers the attribute,
 * so /api/docs/json drifts silently behind routes.yaml.
 */
class ApiDocumentationCoverageTest extends TestCase
{
    /**
     * Routed on purpose without documentation. The bulk controller answers 405
     * for these; the route only claims them so they cannot fall through to
     * api_v2_zone_record, whose record_id pattern also matches "bulk".
     */
    private const UNDOCUMENTED_BY_DESIGN = [
        'GET /v2/zones/{id}/records/bulk',
        'PUT /v2/zones/{id}/records/bulk',
        'DELETE /v2/zones/{id}/records/bulk',
    ];

    public function testEveryRoutedV2OperationIsDocumented(): void
    {
        $routed = $this->routedOperations();
        $documented = $this->documentedOperations();

        $this->assertNotEmpty($routed, 'no v2 routes found - the route loader changed');
        $this->assertNotEmpty($documented, 'no operations generated - the annotation scan changed');

        $allowed = array_map(
            fn(string $operation): string => $this->normalise($operation),
            self::UNDOCUMENTED_BY_DESIGN
        );

        $missing = array_diff($routed, $documented, $allowed);

        $this->assertSame(
            [],
            array_values($missing),
            "Routed but missing from the OpenAPI spec. Add the matching #[OA\\...] attribute, "
                . 'or list it in UNDOCUMENTED_BY_DESIGN with the reason.'
        );
    }

    public function testEveryDocumentedOperationIsRouted(): void
    {
        $extra = array_diff($this->documentedOperations(), $this->routedOperations());

        $this->assertSame(
            [],
            array_values($extra),
            'Documented but not routed - the spec promises an endpoint that answers 404.'
        );
    }

    /**
     * @return string[] "METHOD /v2/..." pairs, parameter names normalised
     */
    private function routedOperations(): array
    {
        $configDir = __DIR__ . '/../../lib/Application/Config';
        $routes = (new YamlFileLoader(new FileLocator([$configDir])))->load('routes.yaml');

        $operations = [];
        foreach ($routes as $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/api/v2/')) {
                continue;
            }

            // The spec is served under /api, so its paths omit that prefix.
            $specPath = substr($path, strlen('/api'));
            foreach ($route->getMethods() as $method) {
                $operations[] = $method . ' ' . $this->normalise($specPath);
            }
        }

        sort($operations);

        return array_values(array_unique($operations));
    }

    /**
     * @return string[] "METHOD /v2/..." pairs, parameter names normalised
     */
    private function documentedOperations(): array
    {
        $scanPath = __DIR__ . '/../../lib/Application/Controller/Api/V2';
        $spec = json_decode((new Generator())->generate([$scanPath], validate: false)->toJson(), true);

        $operations = [];
        foreach ($spec['paths'] ?? [] as $path => $verbs) {
            foreach (array_keys($verbs) as $verb) {
                $operations[] = strtoupper($verb) . ' ' . $this->normalise($path);
            }
        }

        sort($operations);

        return array_values(array_unique($operations));
    }

    /**
     * Collapses placeholder names so {record_id} and {recordId} compare equal.
     * Position, not spelling, is what identifies the endpoint.
     */
    private function normalise(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{}', $path);
    }
}
