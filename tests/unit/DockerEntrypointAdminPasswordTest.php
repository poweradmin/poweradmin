<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * An exported variable reaches the frankenphp process for the container lifetime, so a
 * generated admin password must never be exported.
 */
class DockerEntrypointAdminPasswordTest extends TestCase
{
    public function testAdminCredentialsAreNeverExported(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        $this->assertNotFalse($script);

        $this->assertDoesNotMatchRegularExpression('/^\s*export\s+(ADMIN_PASSWORD|ADMIN_USERNAME|ADMIN_PASSWORD_GENERATED|PA_ADMIN_PASSWORD)\b/m', $script);
        $this->assertDoesNotMatchRegularExpression('/^\s*export\s+\w*PASSWORD\w*=/m', $script);
    }
}
