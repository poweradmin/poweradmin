<?php

declare(strict_types=1);

// Fixture for IndexEntryPointTest: an in-memory database and a switched-off
// password reset, so /password/forgot ends the request through showError()
// without a session or a schema.

return [
    'database' => ['type' => 'sqlite', 'file' => ':memory:'],
    'security' => ['password_reset' => ['enabled' => false]],
    'logging' => ['type' => 'null'],
];
