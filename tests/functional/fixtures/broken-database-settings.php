<?php

declare(strict_types=1);

// Fixture for DynamicUpdateEntryPointTest: the database "file" is a directory,
// which SQLite can never open, so the connection fails before any request is processed.

return [
    'database' => ['type' => 'sqlite', 'file' => __DIR__],
    'logging' => ['type' => 'null'],
];
