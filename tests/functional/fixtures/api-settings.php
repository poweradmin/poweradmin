<?php

declare(strict_types=1);

// Fixture for IndexEntryPointTest: the API and its docs switched on over an
// in-memory database without a schema, so every API request ends before it
// touches a table (unauthenticated) or never needs one (docs).

return [
    'database' => ['type' => 'sqlite', 'file' => ':memory:'],
    'api' => ['enabled' => true, 'docs_enabled' => true],
    'logging' => ['type' => 'null'],
];
