<?php

declare(strict_types=1);

// Fixture for IndexEntryPointTest: a configuration that loads but whose database
// cannot be opened, so the front controller gets past its session decision.

return [
    'database' => ['type' => 'sqlite', 'file' => '/nonexistent/poweradmin.sqlite'],
    'security' => ['session_key' => str_repeat('k', 40)],
];
