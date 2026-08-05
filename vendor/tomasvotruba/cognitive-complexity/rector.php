<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withImportNames()
    ->withPhpSets()
    ->withPreparedSets(typeDeclarations: true, privatization: true, naming: true, deadCode: true, codeQuality: true, codingStyle: true)
    ->withSkip([
        '*/Fixture/*',
        '*/Source/*',
    ]);
