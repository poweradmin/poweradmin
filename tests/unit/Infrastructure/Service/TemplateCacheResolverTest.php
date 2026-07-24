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
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Service\TemplateCacheResolver;
use Psr\Log\NullLogger;

class TemplateCacheResolverTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/pa-template-cache-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            chmod($this->baseDir, 0770);
            $this->removeDir($this->baseDir);
        }
    }

    public function testReturnsExistingWritableDirectory(): void
    {
        mkdir($this->baseDir, 0770, true);

        $resolver = new TemplateCacheResolver(new NullLogger());

        $this->assertSame($this->baseDir, $resolver->resolve($this->baseDir));
    }

    public function testCreatesMissingDirectoryRecursively(): void
    {
        $path = $this->baseDir . '/nested/twig';

        $resolver = new TemplateCacheResolver(new NullLogger());

        $this->assertSame($path, $resolver->resolve($path));
        $this->assertDirectoryExists($path);
    }

    public function testReturnsNullWhenDirectoryCannotBeCreated(): void
    {
        $this->skipIfRoot();
        mkdir($this->baseDir, 0500, true);

        $resolver = new TemplateCacheResolver(new NullLogger());

        $this->assertNull($resolver->resolve($this->baseDir . '/child'));
    }

    public function testReturnsNullWhenDirectoryNotWritable(): void
    {
        $this->skipIfRoot();
        mkdir($this->baseDir, 0500, true);

        $resolver = new TemplateCacheResolver(new NullLogger());

        $this->assertNull($resolver->resolve($this->baseDir));
    }

    public function testEmptyPathFallsBackToAppRootVarCache(): void
    {
        $resolver = new TemplateCacheResolver(new NullLogger());

        $resolved = $resolver->resolve('');

        $appRoot = dirname(__DIR__, 4);
        $this->assertSame($appRoot . '/var/cache/twig', $resolved);
        $this->assertDirectoryExists($resolved);
    }

    private function skipIfRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Directory permission checks are bypassed when running as root.');
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
