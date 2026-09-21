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

namespace Poweradmin\Tests\Unit\Domain\Service\Auth;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Auth\SessionPromotionService;
use Poweradmin\Domain\Service\Auth\UserContextService;

/**
 * Tests for SessionPromotionService, which converts the half-authenticated
 * pending_* session written during MFA verification into a fully
 * authenticated session after the second factor succeeds.
 *
 * Uses the real UserContextService (a thin $_SESSION wrapper); $_SESSION is
 * backed up in setUp() and restored in tearDown().
 */
class SessionPromotionServiceTest extends TestCase
{
    private SessionPromotionService $service;
    private array $sessionBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = [];
        $this->service = new SessionPromotionService(new UserContextService());
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
        parent::tearDown();
    }

    public function testAllPendingKeysArePromotedAndRemoved(): void
    {
        $_SESSION = [
            SessionKeys::PENDING_USERID => 42,
            SessionKeys::PENDING_NAME => 'Jane Doe',
            SessionKeys::PENDING_EMAIL => 'jane@example.com',
            SessionKeys::PENDING_AUTH_USED => 'ldap',
            SessionKeys::PENDING_AUTH_METHOD_USED => 'ldap',
            SessionKeys::PENDING_OIDC_PROVIDER => 'keycloak',
            SessionKeys::PENDING_OIDC_ID_TOKEN => 'id-token-value',
            SessionKeys::PENDING_OAUTH_AVATAR_URL => 'https://example.com/avatar.png',
            SessionKeys::PENDING_SAML_PROVIDER => 'okta',
            SessionKeys::PENDING_SAML_NAME_ID => 'name-id-value',
            SessionKeys::PENDING_SAML_SESSION_INDEX => 'session-index-value',
        ];

        $this->service->promotePendingSession();

        $this->assertSame(42, $_SESSION[SessionKeys::USERID]);
        $this->assertSame('Jane Doe', $_SESSION[SessionKeys::NAME]);
        $this->assertSame('jane@example.com', $_SESSION[SessionKeys::EMAIL]);
        $this->assertSame('ldap', $_SESSION[SessionKeys::AUTH_USED]);
        $this->assertSame('ldap', $_SESSION[SessionKeys::AUTH_METHOD_USED]);
        $this->assertSame('keycloak', $_SESSION[SessionKeys::OIDC_PROVIDER]);
        $this->assertTrue($_SESSION[SessionKeys::OIDC_AUTHENTICATED]);
        $this->assertSame('id-token-value', $_SESSION[SessionKeys::OIDC_ID_TOKEN]);
        $this->assertSame('https://example.com/avatar.png', $_SESSION[SessionKeys::OAUTH_AVATAR_URL]);
        $this->assertSame('okta', $_SESSION[SessionKeys::SAML_PROVIDER]);
        $this->assertTrue($_SESSION[SessionKeys::SAML_AUTHENTICATED]);
        $this->assertSame('name-id-value', $_SESSION[SessionKeys::SAML_NAME_ID]);
        $this->assertSame('session-index-value', $_SESSION[SessionKeys::SAML_SESSION_INDEX]);

        $this->assertArrayNotHasKey(SessionKeys::PENDING_USERID, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::PENDING_NAME, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::PENDING_EMAIL, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::PENDING_AUTH_USED, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::PENDING_AUTH_METHOD_USED, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::PENDING_OIDC_PROVIDER, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::PENDING_OIDC_ID_TOKEN, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::PENDING_OAUTH_AVATAR_URL, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::PENDING_SAML_PROVIDER, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::PENDING_SAML_NAME_ID, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::PENDING_SAML_SESSION_INDEX, $_SESSION);
    }

    public function testAbsentPendingKeysStayAbsent(): void
    {
        $_SESSION = [];

        $this->service->promotePendingSession();

        $this->assertSame([], $_SESSION, 'No session keys may be written when nothing is pending');
    }

    public function testPartialPromotionPromotesOnlyPresentKeys(): void
    {
        $_SESSION = [
            SessionKeys::PENDING_USERID => 7,
            SessionKeys::PENDING_AUTH_USED => 'sql',
        ];

        $this->service->promotePendingSession();

        $this->assertSame(7, $_SESSION[SessionKeys::USERID]);
        $this->assertSame('sql', $_SESSION[SessionKeys::AUTH_USED]);
        $this->assertArrayNotHasKey(SessionKeys::PENDING_USERID, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::PENDING_AUTH_USED, $_SESSION);

        // Untouched keys stay absent - no null writes, no provider flags
        $this->assertArrayNotHasKey(SessionKeys::NAME, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::EMAIL, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::AUTH_METHOD_USED, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::OIDC_PROVIDER, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::OIDC_AUTHENTICATED, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::OIDC_ID_TOKEN, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::OAUTH_AVATAR_URL, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::SAML_PROVIDER, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::SAML_AUTHENTICATED, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::SAML_NAME_ID, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::SAML_SESSION_INDEX, $_SESSION);
    }
}
