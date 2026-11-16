<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The generated settings.php holds the database, SMTP and session secrets, so it must
 * never be group or world readable, whether the entrypoint runs as root or not.
 */
class DockerEntrypointSettingsModeTest extends TestCase
{
    private string $body;

    protected function setUp(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        $this->assertNotFalse($script);
        $this->assertSame(1, preg_match('/^generate_config\(\) \{\n(.*?)\n\}\n/ms', $script, $m), 'generate_config() not found');
        $this->body = $m[1];
    }

    public function testFileIsCreatedUnderRestrictiveUmask(): void
    {
        $umask = strpos($this->body, 'umask 077');
        $write = strpos($this->body, 'cat > "${CONFIG_FILE}"');

        $this->assertNotFalse($umask, 'generate_config() does not set umask 077');
        $this->assertNotFalse($write);
        $this->assertLessThan($write, $umask, 'umask must be set before the file is created');
        $this->assertStringContainsString('umask "${previous_umask}"', $this->body, 'the previous umask must be restored');
    }

    public function testFinalModeIs640ForRootAndNonRoot(): void
    {
        $this->assertStringNotContainsString('chmod 644', $this->body);
        $this->assertSame(1, preg_match('/^    chmod 640 "\$\{CONFIG_FILE\}"/m', $this->body), 'chmod 640 must run outside the root-only block');
    }
}
