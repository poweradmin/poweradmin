<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 */

namespace Poweradmin\Tests\Unit\Infrastructure\Logger;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\Logger;
use Psr\Log\NullLogger;

class LoggerFromConfigTest extends TestCase
{
    public function testNativeTypeBuildsTheErrorLogLogger(): void
    {
        $this->assertInstanceOf(Logger::class, Logger::fromConfig($this->config('native')));
    }

    public function testAnyOtherTypeDropsMessages(): void
    {
        $this->assertInstanceOf(NullLogger::class, Logger::fromConfig($this->config('null')));
        $this->assertInstanceOf(NullLogger::class, Logger::fromConfig($this->config(null)));
    }

    private function config(?string $type): ConfigurationInterface
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, $default = null) => match ($key) {
            'type' => $type,
            'level' => 'info',
            default => $default,
        });

        return $config;
    }
}
