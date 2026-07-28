<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;

/**
 * templ_id reaches this repository from the request. The DELETE that clears a
 * template's permissions concatenated it raw, so templ_id=1 OR 1=1 removed every
 * row in perm_templ_items and stripped all permissions from every template.
 */
class PermissionTemplateSqlQuotingTest extends TestCase
{
    public function testTemplateIdIsQuotedInTheDeleteStatement(): void
    {
        $queries = $this->runUpdateWith('1 OR 1=1');

        $delete = $this->firstMatching($queries, 'DELETE FROM perm_templ_items');

        $this->assertNotNull($delete, 'The permission list is cleared before being rewritten');
        $this->assertStringNotContainsString(
            'templ_id = 1 OR 1=1',
            $delete,
            'A raw templ_id would delete every permission row in the table'
        );
        $this->assertStringContainsString("templ_id = 'QUOTED:1 OR 1=1'", $delete);
    }

    public function testAnOrdinaryTemplateIdStillReachesTheDelete(): void
    {
        $queries = $this->runUpdateWith('5');

        $delete = $this->firstMatching($queries, 'DELETE FROM perm_templ_items');

        $this->assertStringContainsString("templ_id = 'QUOTED:5'", $delete);
    }

    /**
     * @return array<int, string> Every statement the repository executed
     */
    private function runUpdateWith(string $templId): array
    {
        $queries = [];

        $db = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['quote', 'query'])
            ->getMock();

        // Mirrors PDOLayer::quote(), which wraps the value in quotes whatever the type.
        $db->method('quote')->willReturnCallback(
            static fn($value, $type = null): string => "'QUOTED:" . $value . "'"
        );
        $db->method('query')->willReturnCallback(
            static function (string $query) use (&$queries) {
                $queries[] = $query;
                return null;
            }
        );

        $repository = new DbPermissionTemplateRepository($db);
        $repository->updatePermissionTemplateDetails([
            'templ_id' => $templId,
            'templ_name' => 'Ordinary',
            'templ_descr' => '',
        ]);

        return $queries;
    }

    /**
     * @param array<int, string> $queries
     */
    private function firstMatching(array $queries, string $needle): ?string
    {
        foreach ($queries as $query) {
            if (str_contains($query, $needle)) {
                return $query;
            }
        }

        return null;
    }
}
