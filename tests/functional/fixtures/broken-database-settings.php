<?php

declare(strict_types=1);

// Fixture for DynamicUpdateEntryPointTest: a database file that cannot be
// opened, so the connection fails before any request is processed.

return [
    'database' => ['type' => 'sqlite', 'file' => '/nonexistent/poweradmin.sqlite'],
    'logging' => ['type' => 'null'],
];
