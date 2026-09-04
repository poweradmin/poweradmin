<?php

declare(strict_types=1);

// Fixture for IndexEntryPointTest: a settings file that fails to load, so the
// front controller has to shape a configuration failure it cannot recover from.

throw new RuntimeException('Simulated configuration failure');
