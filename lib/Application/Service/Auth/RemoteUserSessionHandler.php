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

namespace Poweradmin\Application\Service\Auth;

use Poweradmin\Application\Http\RequestContext;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Web\RedirectService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Enum\LoginFailureReason;
use Poweradmin\Domain\Port\SessionInterface;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\ValueObject\RemoteUserInfo;
use Poweradmin\Infrastructure\Session\AuthFlowSessionKeys;
use Poweradmin\Infrastructure\Session\FlashMessage;
use Poweradmin\Infrastructure\Session\MfaSessionManager;
use Poweradmin\Infrastructure\Session\SessionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Keeps the session in step with the user the web server signed in: starts a
 * session for them, ends one whose web server user changed or went away, and
 * leaves sessions started by any other sign-in method alone.
 */
final class RemoteUserSessionHandler
{
    private const PROVIDER_ID = 'web server';

    public function __construct(
        private readonly RemoteUserIdentitySource $source,
        private readonly UserProvisioningService $provisioning,
        private readonly SessionInterface $session,
        private readonly SessionService $sessionService,
        private readonly AuthenticationService $authService,
        private readonly AuditService $audit,
        private readonly MfaService $mfaService,
        private readonly ConfigurationInterface $config,
        private readonly RedirectService $redirectService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Runs before the idle-timeout check on every authenticated request except a
     * posted login form. May end the session or redirect, both by halting the request.
     *
     * @param string $path The request path without base_url_prefix
     */
    public function apply(int $idleTimeout, string $path): void
    {
        $remoteSession = $this->isRemoteUserSession();
        $identity = $this->source->identity();

        if ($remoteSession) {
            if ($identity === null) {
                $this->logger->info('Ending web server session of {username}: the web server no longer signs anyone in', [
                    'username' => $this->session->get(SessionKeys::USERLOGIN, 'unknown')
                ]);
                $this->authService->logout(new FlashMessage(_('Session expired, please login again.'), 'danger'));
                return;
            }

            if ($identity->getUsername() === $this->session->get(SessionKeys::REMOTE_USER_IDENTITY)) {
                if ($this->awaitsMfa()) {
                    // Logout and the verification page itself must stay reachable
                    if ($path === '/logout' || $path === '/mfa/verify') {
                        return;
                    }
                    // With MFA switched off since, the pending session can never be promoted
                    if ($this->config->get('security', 'mfa.enabled', false)) {
                        $this->redirectToMfa();
                        return;
                    }
                } elseif (!$this->idleExpired($idleTimeout)) {
                    return;
                }
            }

            // A different web server user, or an idle session the web server still vouches for
            $this->sessionService->endSession();
        } elseif ($this->session->has(SessionKeys::USERID) || $this->session->has(SessionKeys::PENDING_USERID)) {
            return;
        }

        if ($identity === null || $this->session->get(AuthFlowSessionKeys::REMOTE_USER_SIGNED_OUT)) {
            return;
        }

        $this->signIn($identity);
    }

    private function isRemoteUserSession(): bool
    {
        return $this->session->get(SessionKeys::AUTH_METHOD_USED) === AuthMethod::REMOTE_USER->value
            || $this->session->get(SessionKeys::PENDING_AUTH_METHOD_USED) === AuthMethod::REMOTE_USER->value;
    }

    private function awaitsMfa(): bool
    {
        return !$this->session->has(SessionKeys::USERID) && $this->session->has(SessionKeys::PENDING_USERID);
    }

    private function idleExpired(int $idleTimeout): bool
    {
        $lastActivity = $this->session->get(SessionKeys::LASTMOD);

        return is_int($lastActivity) && time() - $lastActivity > $idleTimeout;
    }

    private function signIn(RemoteUserInfo $identity): void
    {
        // Nothing from an earlier visit (a half-done password or MFA login) carries over
        $this->sessionService->endSession();

        // The audit line names the session's username
        $this->session->set(SessionKeys::USERLOGIN, $identity->getUsername());

        $userId = $this->provisioning->provisionUser($identity, self::PROVIDER_ID);
        if ($userId === null) {
            $this->refuse(null, _('Authentication failed: Unable to create or update user account'));
            return;
        }
        if (!$this->provisioning->isActiveUser($userId)) {
            $this->refuse(LoginFailureReason::ACCOUNT_DISABLED, _('The user account is disabled.'));
            return;
        }

        // The attributes are optional, so the stored account, not the request, fills the session
        $profile = $this->provisioning->accountProfile($userId);
        $username = $profile['username'] ?? $identity->getUsername();
        $fullname = ($profile['fullname'] ?? '') !== '' ? $profile['fullname'] : $username;
        $email = $profile['email'] ?? '';

        $this->session->regenerateId(true);
        $this->session->set(SessionKeys::USERLOGIN, $username);
        $this->session->set(SessionKeys::REMOTE_USER_IDENTITY, $identity->getUsername());
        $this->session->set(SessionKeys::LASTMOD, time());
        // A token planted before sign-in must not survive it
        (new CsrfTokenService($this->session))->regenerateToken();
        $this->audit->logLoginSuccess(AuthMethod::REMOTE_USER);

        $mfa = new MfaSessionManager($this->session, $this->logger);
        if ($this->config->get('security', 'mfa.enabled', false) && $this->mfaService->isMfaEnabled($userId)) {
            // No userid until the second factor is verified, so nothing treats the session as signed in
            $this->session->set(SessionKeys::PENDING_USERID, $userId);
            $this->session->set(SessionKeys::PENDING_NAME, $fullname);
            $this->session->set(SessionKeys::PENDING_EMAIL, $email);
            $this->session->set(SessionKeys::PENDING_AUTH_USED, AuthMethod::REMOTE_USER->value);
            $this->session->set(SessionKeys::PENDING_AUTH_METHOD_USED, AuthMethod::REMOTE_USER->value);
            $mfa->setMfaRequired($userId);
            $this->redirectToMfa();
            return;
        }

        $this->session->set(SessionKeys::USERID, $userId);
        $this->session->set(SessionKeys::NAME, $fullname);
        $this->session->set(SessionKeys::USERFULLNAME, $fullname);
        $this->session->set(SessionKeys::EMAIL, $email);
        $this->session->set(SessionKeys::USEREMAIL, $email);
        $this->session->set(SessionKeys::AUTH_USED, AuthMethod::REMOTE_USER->value);
        $this->session->set(SessionKeys::AUTH_METHOD_USED, AuthMethod::REMOTE_USER->value);
        $this->session->set(SessionKeys::AUTHENTICATED, true);
        $mfa->setMfaNotRequired();
    }

    /**
     * Sends the user to the login page with the reason, marked signed out so the
     * web server's user is not tried again on every page until they ask for it.
     */
    private function refuse(?LoginFailureReason $reason, string $message): void
    {
        $this->audit->logLoginFailed(AuthMethod::REMOTE_USER, $reason);
        $this->sessionService->endSession();
        $this->session->set(AuthFlowSessionKeys::REMOTE_USER_SIGNED_OUT, true);
        $this->authService->auth(new FlashMessage($message, 'danger'));
    }

    private function redirectToMfa(): void
    {
        if (RequestContext::isApiRequest()) {
            $this->redirectService->send(new JsonResponse(['error' => true, 'message' => 'Multi-factor authentication required'], 403));
            return;
        }

        $this->session->writeClose();
        $this->redirectService->redirectTo($this->config->get('interface', 'base_url_prefix', '') . '/mfa/verify');
    }
}
