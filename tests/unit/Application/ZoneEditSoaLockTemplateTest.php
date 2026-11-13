<?php

namespace Poweradmin\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The zone edit page locks a record row where the server refuses the edit: the SOA is
 * editable for every level that can edit the zone except own_as_client (and no edit right).
 */
class ZoneEditSoaLockTemplateTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, bool}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (['default', 'modern'] as $theme) {
            foreach (
                [
                    ['SOA', 'all', false], ['SOA', 'own', false], ['SOA', 'own_as_client', true], ['SOA', 'none', true],
                    ['NS', 'own', false], ['NS', 'own_as_client', true], ['LUA', 'own_as_client', true],
                    ['A', 'own_as_client', false], ['A', 'own', false],
                ] as [$type, $level, $locked]
            ) {
                $cases["$theme $type $level"] = [$theme, $type, $level, $locked];
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function testRecordLockMatchesTheServerRule(string $theme, string $type, string $permEdit, bool $expected): void
    {
        $template = file_get_contents(dirname(__DIR__, 3) . "/templates/$theme/edit.html");
        $this->assertSame(1, preg_match('/\{% set record_locked = .*? %\}/', $template, $match));

        $twig = new Environment(new ArrayLoader(['t' => $match[0] . '{{ record_locked ? "locked" : "open" }}']));

        $this->assertSame(
            $expected ? 'locked' : 'open',
            $twig->render('t', ['r' => ['type' => $type], 'perm_edit' => $permEdit])
        );
    }
}
