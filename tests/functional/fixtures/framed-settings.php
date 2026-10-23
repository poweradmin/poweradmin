<?php

declare(strict_types=1);

// Fixture for IndexEntryPointTest: framing allowed from one origin.

return [
    'database' => ['type' => 'sqlite', 'file' => ':memory:'],
    'api' => ['enabled' => true],
    'interface' => ['frame_ancestors' => ['https://portal.example.com']],
    'logging' => ['type' => 'null'],
];
