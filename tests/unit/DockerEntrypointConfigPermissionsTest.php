<?php

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PA_CONFIG_PATH may point into a shared directory (/etc) or a read-only mount, so the
 * entrypoint must touch only the config files themselves and survive a failing chown.
 */
class DockerEntrypointConfigPermissionsTest extends TestCase
{
    private string $body;

    protected function setUp(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        $this->assertNotFalse($script);
        $this->assertSame(1, preg_match('/^setup_permissions\(\) \{\n(.*?)\n\}\n/ms', $script, $m), 'setup_permissions() not found');
        $this->body = $m[1];
    }

    private function configBlock(): string
    {
        $start = strpos($this->body, 'config_dir=$(dirname');
        $end = strpos($this->body, 'Caddy data directory');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($this->body, $start, $end - $start);
    }

    public function testConfigDirectoryIsNotChangedRecursively(): void
    {
        $block = $this->configBlock();

        $this->assertDoesNotMatchRegularExpression('/\b(chown|chmod)\s+-[a-zA-Z]*R/', $block);
    }

    public function testConfigFileAndDefaultsAreHandledIndividually(): void
    {
        $block = $this->configBlock();

        $this->assertStringContainsString('in "${CONFIG_FILE}" "${config_dir}/settings.defaults.php"; do', $block);
        $this->assertStringContainsString('[ -e "${config_target}" ]', $block);
    }

    public function testFailureOnReadOnlyMountOnlyWarns(): void
    {
        $block = $this->configBlock();

        $this->assertMatchesRegularExpression('/chown www-data:www-data "\$\{config_target\}" 2>\/dev\/null \|\| log "WARNING/', $block);
        $this->assertStringNotContainsString('chmod', $block, 'the generated 640 mode must be left alone');
        $this->assertDoesNotMatchRegularExpression('/chown[^\n]*"\$\{config_dir\}"/', $block, 'the directory itself must not be chowned');
    }

    public function testRootDefaultsRestoreSurvivesAReadOnlyMount(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/docker-entrypoint.sh');
        $this->assertNotFalse($script);

        $this->assertMatchesRegularExpression(
            '/if \[ "\$IS_ROOT" = true \]; then\n\s+cp \/usr\/local\/share\/settings\.defaults\.php "\$\{defaults_file\}" 2>\/dev\/null \\\\\s+&& chown www-data:www-data "\$\{defaults_file\}" \\\\\s+\|\| log "WARNING/',
            $script
        );
    }
}
