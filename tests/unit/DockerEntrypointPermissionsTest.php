<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The entrypoint runs as root, so what it chowns is an operator-visible blast radius:
 * PA_CONFIG_PATH may point into a shared directory or a read-only mount.
 */
class DockerEntrypointPermissionsTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        $this->assertNotFalse($script);
        $this->script = $script;
    }

    private function functionBody(string $name): string
    {
        $this->assertSame(1, preg_match('/^' . $name . '\(\) \{\n(.*?)\n\}\n/ms', $this->script, $m), $name . '() not found');
        return $m[1];
    }

    public function testConfigDirectoryIsNotChangedRecursivelyOrAtAll(): void
    {
        $body = $this->functionBody('setup_permissions');

        $this->assertSame(0, preg_match('/(chown|chmod)\s+-R[^\n]*config_dir/', $body), 'the custom config directory must not be changed recursively');
        $this->assertSame(0, preg_match('/chown [^\n]*"\$\{config_dir\}"\s*(2>|$)/m', $body), 'the directory itself must not be chowned');
        $this->assertStringContainsString('for config_target in "${CONFIG_FILE}" "${config_dir}/settings.defaults.php"', $body);
        $this->assertStringNotContainsString('chmod', $body, 'chmod g+w would undo the 640 on settings.php');
    }

    public function testConfigOwnershipFailureDoesNotAbortBoot(): void
    {
        $body = $this->functionBody('setup_permissions');

        $this->assertSame(1, preg_match('/chown www-data:www-data "\$\{config_target\}" 2>\/dev\/null \|\| log "WARNING/', $body));
    }

    public function testRestoringDefaultsIntoAReadOnlyDirectoryDoesNotAbortBoot(): void
    {
        $this->assertStringContainsString(
            "            cp /usr/local/share/settings.defaults.php \"\${defaults_file}\" 2>/dev/null || \\\n"
            . "                log \"WARNING: Could not restore settings.defaults.php - directory may not be writable\"\n"
            . "            chown www-data:www-data \"\${defaults_file}\" 2>/dev/null || true\n",
            $this->script
        );
    }
}
