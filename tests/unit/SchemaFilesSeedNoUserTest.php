<?php

namespace unit;

use PHPUnit\Framework\TestCase;

class SchemaFilesSeedNoUserTest extends TestCase
{
    /**
     * @dataProvider schemaFiles
     */
    public function testSchemaDoesNotSeedAnAccount(string $file): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/sql/' . $file);

        $this->assertIsString($sql);
        $this->assertDoesNotMatchRegularExpression('/INSERT\s+INTO\s+[`"]?users[`"]?\s/i', $sql);
    }

    public static function schemaFiles(): array
    {
        return [
            ['poweradmin-mysql-db-structure.sql'],
            ['poweradmin-pgsql-db-structure.sql'],
            ['poweradmin-sqlite-db-structure.sql'],
        ];
    }
}
