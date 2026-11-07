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

    public function testPermissionIdsAreStoredAsTheIntTheGuardChecked(): void
    {
        // MySQL rounds a stored '52.5' up to 53 (user_is_ueberuser) while the guard saw (int) 52
        $queries = $this->runUpdateWith('5', ['52.5', '5.35e1', '7', ['53']]);

        $inserts = array_values(array_filter($queries, static fn(string $q): bool => str_contains($q, 'INSERT INTO perm_templ_items')));

        $this->assertCount(3, $inserts, 'A non-scalar id is skipped, as the guard skips it');
        $this->assertStringContainsString("'QUOTED:52')", $inserts[0]);
        $this->assertStringContainsString("'QUOTED:53')", $inserts[1]);
        $this->assertStringContainsString("'QUOTED:7')", $inserts[2]);
    }

    /**
     * @param ?array<mixed> $permIds
     * @return array<int, string> Every statement the repository executed
     */
    private function runUpdateWith(string $templId, ?array $permIds = null): array
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
        $details = [
            'templ_id' => $templId,
            'templ_name' => 'Ordinary',
            'templ_descr' => '',
        ];
        if ($permIds !== null) {
            $details['perm_id'] = $permIds;
        }
        $repository->updatePermissionTemplateDetails($details);

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
