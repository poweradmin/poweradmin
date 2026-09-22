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
use Poweradmin\Infrastructure\Session\ArraySession;

/**
 * Tests for SessionPromotionService, which converts the half-authenticated
 * pending_* session written during MFA verification into a fully
 * authenticated session after the second factor succeeds.
 *
 * Uses the real UserContextService over an in-memory session, so the keys it
 * promotes and drops are the ones the authenticators write.
 */
class SessionPromotionServiceTest extends TestCase
{
    private ArraySession $session;

    private SessionPromotionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new ArraySession();
        $this->service = new SessionPromotionService(new UserContextService($this->session));
    }

    public function testAllPendingKeysArePromotedAndRemoved(): void
    {
        $this->session = new ArraySession([
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
        ]);
        $this->service = new SessionPromotionService(new UserContextService($this->session));

        $this->service->promotePendingSession();

        $this->assertSame(42, $this->session->get(SessionKeys::USERID));
        $this->assertSame('Jane Doe', $this->session->get(SessionKeys::NAME));
        $this->assertSame('jane@example.com', $this->session->get(SessionKeys::EMAIL));
        $this->assertSame('ldap', $this->session->get(SessionKeys::AUTH_USED));
        $this->assertSame('ldap', $this->session->get(SessionKeys::AUTH_METHOD_USED));
        $this->assertSame('keycloak', $this->session->get(SessionKeys::OIDC_PROVIDER));
        $this->assertTrue($this->session->get(SessionKeys::OIDC_AUTHENTICATED));
        $this->assertSame('id-token-value', $this->session->get(SessionKeys::OIDC_ID_TOKEN));
        $this->assertSame('https://example.com/avatar.png', $this->session->get(SessionKeys::OAUTH_AVATAR_URL));
        $this->assertSame('okta', $this->session->get(SessionKeys::SAML_PROVIDER));
        $this->assertTrue($this->session->get(SessionKeys::SAML_AUTHENTICATED));
        $this->assertSame('name-id-value', $this->session->get(SessionKeys::SAML_NAME_ID));
        $this->assertSame('session-index-value', $this->session->get(SessionKeys::SAML_SESSION_INDEX));

        $this->assertFalse($this->session->has(SessionKeys::PENDING_USERID));
        $this->assertFalse($this->session->has(SessionKeys::PENDING_NAME));
        $this->assertFalse($this->session->has(SessionKeys::PENDING_EMAIL));
        $this->assertFalse($this->session->has(SessionKeys::PENDING_AUTH_USED));
        $this->assertFalse($this->session->has(SessionKeys::PENDING_AUTH_METHOD_USED));
        $this->assertFalse($this->session->has(SessionKeys::PENDING_OIDC_PROVIDER));
        $this->assertFalse($this->session->has(SessionKeys::PENDING_OIDC_ID_TOKEN));
        $this->assertFalse($this->session->has(SessionKeys::PENDING_OAUTH_AVATAR_URL));
        $this->assertFalse($this->session->has(SessionKeys::PENDING_SAML_PROVIDER));
        $this->assertFalse($this->session->has(SessionKeys::PENDING_SAML_NAME_ID));
        $this->assertFalse($this->session->has(SessionKeys::PENDING_SAML_SESSION_INDEX));
    }

    public function testAbsentPendingKeysStayAbsent(): void
    {
        $this->session = new ArraySession();

        $this->service->promotePendingSession();

        $this->assertSame([], $this->session->all(), 'No session keys may be written when nothing is pending');
    }

    public function testPartialPromotionPromotesOnlyPresentKeys(): void
    {
        $this->session = new ArraySession([
            SessionKeys::PENDING_USERID => 7,
            SessionKeys::PENDING_AUTH_USED => 'sql',
        ]);
        $this->service = new SessionPromotionService(new UserContextService($this->session));

        $this->service->promotePendingSession();

        $this->assertSame(7, $this->session->get(SessionKeys::USERID));
        $this->assertSame('sql', $this->session->get(SessionKeys::AUTH_USED));
        $this->assertFalse($this->session->has(SessionKeys::PENDING_USERID));
        $this->assertFalse($this->session->has(SessionKeys::PENDING_AUTH_USED));

        // Untouched keys stay absent - no null writes, no provider flags
        $this->assertFalse($this->session->has(SessionKeys::NAME));
        $this->assertFalse($this->session->has(SessionKeys::EMAIL));
        $this->assertFalse($this->session->has(SessionKeys::AUTH_METHOD_USED));
        $this->assertFalse($this->session->has(SessionKeys::OIDC_PROVIDER));
        $this->assertFalse($this->session->has(SessionKeys::OIDC_AUTHENTICATED));
        $this->assertFalse($this->session->has(SessionKeys::OIDC_ID_TOKEN));
        $this->assertFalse($this->session->has(SessionKeys::OAUTH_AVATAR_URL));
        $this->assertFalse($this->session->has(SessionKeys::SAML_PROVIDER));
        $this->assertFalse($this->session->has(SessionKeys::SAML_AUTHENTICATED));
        $this->assertFalse($this->session->has(SessionKeys::SAML_NAME_ID));
        $this->assertFalse($this->session->has(SessionKeys::SAML_SESSION_INDEX));
    }
}
