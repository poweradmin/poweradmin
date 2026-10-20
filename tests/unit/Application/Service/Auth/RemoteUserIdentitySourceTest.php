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
namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\RemoteUserIdentitySource;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

#[CoversClass(RemoteUserIdentitySource::class)]
class RemoteUserIdentitySourceTest extends TestCase
{
    private function source(array $settings, array $server): RemoteUserIdentitySource
    {
        return new RemoteUserIdentitySource(
            new FakeConfiguration(['remote_user' => $settings + ['enabled' => true]]),
            $server,
            new NullLogger()
        );
    }

    public function testDisabledFeatureIgnoresTheServerVariable(): void
    {
        $this->assertNull($this->source(['enabled' => false], ['REMOTE_USER' => 'alice'])->identity());
    }

    public function testReadsRemoteUserByDefault(): void
    {
        $identity = $this->source([], ['REMOTE_USER' => ' alice '])->identity();

        $this->assertSame('alice', $identity?->getUsername());
        $this->assertSame([], $identity->getGroups());
    }

    public function testNoUserWhenTheVariableIsMissingOrEmpty(): void
    {
        $this->assertNull($this->source([], [])->identity());
        $this->assertNull($this->source([], ['REMOTE_USER' => '   '])->identity());
    }

    /**
     * HTTP_* variables are request headers a client sets, so a server variable
     * setting must never read one.
     */
    public function testServerVariableRefusesClientHeaders(): void
    {
        $server = ['HTTP_X_REMOTE_USER' => 'admin', 'http_x_remote_user' => 'admin'];

        $this->assertNull($this->source(['server_variable' => 'HTTP_X_REMOTE_USER'], $server)->identity());
        $this->assertNull($this->source(['server_variable' => 'http_x_remote_user'], $server)->identity());
    }

    /**
     * PHP fills PHP_AUTH_USER from the client's Authorization header whenever the
     * web server passes it through, with or without checking the password.
     */
    public function testServerVariableRefusesValuesTheClientControls(): void
    {
        $server = ['PHP_AUTH_USER' => 'admin', 'REDIRECT_HTTP_X_USER' => 'admin', 'REDIRECT_REMOTE_USER' => 'alice'];

        $this->assertNull($this->source(['server_variable' => 'PHP_AUTH_USER'], $server)->identity());
        $this->assertNull($this->source(['server_variable' => 'REDIRECT_HTTP_X_USER'], $server)->identity());
        $this->assertSame('alice', $this->source(['server_variable' => 'REDIRECT_REMOTE_USER'], $server)->identity()?->getUsername());
    }

    public function testAttributesInServerVariableModeRefuseClientHeaders(): void
    {
        $identity = $this->source(
            ['email_attribute' => 'HTTP_REMOTE_EMAIL', 'groups_attribute' => 'HTTP_REMOTE_GROUPS', 'name_attribute' => 'MELLON_cn'],
            ['REMOTE_USER' => 'alice', 'HTTP_REMOTE_EMAIL' => 'x@example.com', 'HTTP_REMOTE_GROUPS' => 'admins', 'MELLON_cn' => 'Alice A']
        )->identity();

        $this->assertSame('', $identity?->getEmail());
        $this->assertSame([], $identity->getGroups());
        $this->assertSame('Alice A', $identity->getDisplayName());
    }

    public function testHeaderIsIgnoredFromAPeerNotInTheList(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_REMOTE_USER' => 'admin', 'REMOTE_USER' => 'alice'];

        $this->assertNull($this->source(['header' => 'Remote-User', 'trusted_proxies' => []], $server)->identity());
        $this->assertNull($this->source(['header' => 'Remote-User', 'trusted_proxies' => ['10.0.0.6']], $server)->identity());
    }

    public function testHeaderFromATrustedProxyCarriesUserAndAttributes(): void
    {
        $identity = $this->source(
            [
                'header' => 'Remote-User',
                'trusted_proxies' => ['10.0.0.0/24'],
                'email_attribute' => 'Remote-Email',
                'name_attribute' => 'Remote-Name',
                'groups_attribute' => 'Remote-Groups',
            ],
            [
                'REMOTE_ADDR' => '10.0.0.5',
                'REMOTE_USER' => 'someone-else',
                'HTTP_REMOTE_USER' => 'alice',
                'HTTP_REMOTE_EMAIL' => 'alice@example.com',
                'HTTP_REMOTE_NAME' => 'Alice Liddell',
                'HTTP_REMOTE_GROUPS' => 'dns-admins, dns-editors,,dns-admins',
            ]
        )->identity();

        $this->assertSame('alice', $identity?->getUsername(), 'header mode never falls back to the server variable');
        $this->assertSame('alice@example.com', $identity->getEmail());
        $this->assertSame('Alice Liddell', $identity->getDisplayName());
        $this->assertSame(['dns-admins', 'dns-editors'], $identity->getGroups());
    }

    public function testGroupsSeparatorIsConfigurable(): void
    {
        $identity = $this->source(
            ['groups_attribute' => 'MELLON_groups', 'groups_separator' => ';'],
            ['REMOTE_USER' => 'alice', 'MELLON_groups' => 'a;b']
        )->identity();

        $this->assertSame(['a', 'b'], $identity?->getGroups());
    }

    public function testControlCharactersAreDroppedFromGroups(): void
    {
        $identity = $this->source(['groups_attribute' => 'GROUPS'], ['REMOTE_USER' => 'alice', 'GROUPS' => "admins\r\nfake,\x07"])->identity();

        $this->assertSame(['adminsfake'], $identity?->getGroups());
    }

    public function testInvalidEmailIsDropped(): void
    {
        $identity = $this->source(['email_attribute' => 'MAIL'], ['REMOTE_USER' => 'alice', 'MAIL' => 'not-an-email'])->identity();

        $this->assertSame('', $identity?->getEmail());
    }

    public function testStripRealmRemovesDomainAndRealm(): void
    {
        $this->assertSame('alice', $this->source(['strip_realm' => true], ['REMOTE_USER' => 'alice@EXAMPLE.COM'])->identity()?->getUsername());
        $this->assertSame('alice', $this->source(['strip_realm' => true], ['REMOTE_USER' => 'CORP\\alice'])->identity()?->getUsername());
        $this->assertSame('alice@EXAMPLE.COM', $this->source([], ['REMOTE_USER' => 'alice@EXAMPLE.COM'])->identity()?->getUsername());
        $this->assertNull($this->source(['strip_realm' => true], ['REMOTE_USER' => '@EXAMPLE.COM'])->identity());
    }

    public static function unusableNames(): array
    {
        return [
            'inner space' => ['alice smith'],
            'tab' => ["alice\tsmith"],
            'newline' => ["alice\nadmin"],
            'nul byte' => ["alice\0"],
            'trailing newline' => ["alice\n"],
            'too long' => [str_repeat('a', 65)],
            'invalid utf-8' => ["\xff\xfe"],
        ];
    }

    #[DataProvider('unusableNames')]
    public function testUnusableNamesAreRefused(string $name): void
    {
        $this->assertNull($this->source([], ['REMOTE_USER' => $name])->identity());
    }

    public function testLongestAllowedNameIsAccepted(): void
    {
        $name = str_repeat('a', 64);

        $this->assertSame($name, $this->source([], ['REMOTE_USER' => $name])->identity()?->getUsername());
    }
}
