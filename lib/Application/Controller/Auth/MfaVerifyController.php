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

namespace Poweradmin\Application\Controller\Auth;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Http\ClientContext;
use Exception;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Infrastructure\Session\MfaSessionManager;
use Poweradmin\Application\Service\Auth\AuthFlowSessionKeys;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Domain\Service\Auth\SessionPromotionService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use RuntimeException;

/**
 * Handles the MFA verification step after login, with throttling of failed attempts.
 */
class MfaVerifyController extends BaseController
{
    private MfaService $mfaService;
    private CsrfTokenService $csrfTokenService;
    private UserContextService $userContextService;
    private ClientContext $client;
    private LoginAttemptService $loginAttemptService;

    public function __construct(array $request, ?ControllerEnvironment $environment = null)
    {
        parent::__construct($request, false, $environment);

        $this->mfaService = $this->services()->mfaService();

        $this->csrfTokenService = new CsrfTokenService();
        $this->userContextService = new UserContextService();
        $this->client = $this->services()->clientContext();
        $this->loginAttemptService = $this->services()->loginAttemptService();
    }

    /**
     * This flow validates its own one-shot `mfa_token` instead of the
     * session-wide form token.
     */
    protected function requiresCsrfValidation(): bool
    {
        return false;
    }

    public function run(): void
    {
        // Check if MFA is globally enabled or this is a logout request
        $logout = $this->httpRequest->getQueryParam('logout');
        if (!$this->config->get('security', 'mfa.enabled', false) || $logout !== null) {
            // If MFA is disabled or this is a logout request, but we have MFA session flags, clear them
            if ($this->userContextService->hasSessionData(AuthFlowSessionKeys::MFA_REQUIRED)) {
                $this->userContextService->unsetSessionData(AuthFlowSessionKeys::MFA_REQUIRED);
            }
            $this->userContextService->unsetSessionData(AuthFlowSessionKeys::MFA_STATE);

            // If this is a logout request, do a proper logout
            if ($logout !== null) {
                session_regenerate_id(true);
                session_unset();

                // Build redirect URL with base_url_prefix support for subfolder deployments
                $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
                $redirectUrl = $baseUrlPrefix . '/login';
                header("Location: $redirectUrl");
            } else {
                // Otherwise just mark as authenticated
                $this->userContextService->setSessionData(SessionKeys::AUTHENTICATED, true);
                session_regenerate_id(true);

                // Build redirect URL with base_url_prefix support for subfolder deployments
                $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
                $redirectUrl = $baseUrlPrefix . '/';
                header("Location: $redirectUrl");
            }
            exit;
        }

        // Check if we have the necessary session data
        // During MFA verification, userid is stored as pending_userid to prevent API bypass
        $userId = $this->userContextService->getLoggedInUserId() ?? $this->userContextService->getSessionData(SessionKeys::PENDING_USERID);
        if (!$this->userContextService->getLoggedInUsername() || !$userId || !$this->userContextService->hasSessionData(AuthFlowSessionKeys::MFA_REQUIRED)) {
            $this->redirect('/');
        }

        // If the user is already fully authenticated (MFA passed), redirect to index
        // Use our centralized MFA session manager to check state
        if (!MfaSessionManager::isMfaRequired()) {
            $this->logger->debug('MFA not required according to MfaSessionManager, redirecting to /');
            $this->redirect('/');
        }

        // Make verification more robust by just checking for the code
        if ($this->httpRequest->getPostParam('mfa_code') !== null) {
            $this->handleMfaVerification();
            return;
        }

        // Otherwise, display the MFA form
        $this->displayMfaForm();
    }

    private function handleMfaVerification(): void
    {
        // Basic logging
        $this->logger->debug('[MfaVerifyController] Verification attempt started');

        $code = $this->httpRequest->getPostParam('mfa_code', '');
        // During MFA verification, userid is stored as pending_userid to prevent API bypass
        $userId = $this->userContextService->getLoggedInUserId() ?? $this->userContextService->getSessionData(SessionKeys::PENDING_USERID);
        $mfaToken = $this->httpRequest->getPostParam('mfa_token', '');

        // Validate CSRF token for security
        if (!$this->csrfTokenService->validateToken($mfaToken, AuthFlowSessionKeys::MFA_TOKEN)) {
            $this->logger->warning('[MfaVerifyController] Invalid CSRF token for user ID: {user_id}', ['user_id' => $userId]);
            $this->displayMfaForm(_('Invalid security token. Please try again.'), 'danger');
            return;
        }

        $username = $this->userContextService->getLoggedInUsername() ?? '';
        // A recovery code is the documented way back into a locked account, so it is checked
        // before the counter gate; a blacklisted address stays out regardless
        $recovered = false;
        if ($this->isMfaThrottled($username, (int)$userId)) {
            $recovered = !$this->loginAttemptService->isIpBlacklisted($this->client->ip)
                && $this->mfaService->consumeRecoveryCode($userId, $code);
            if (!$recovered) {
                $this->logger->warning('[MfaVerifyController] Account locked, refusing MFA attempt for user ID: {user_id}', ['user_id' => $userId]);
                // Audited like any wrong code, but not counted, so a bot cannot hold the window open
                $this->services()->auditService()->logMfaFailed($this->mfaService->getMfaType($userId) ?? 'unknown');
                $this->displayMfaForm(_('Too many failed attempts. Please try again later.'), 'danger');
                return;
            }
        }

        // Get user MFA record
        try {
            $userMfa = $this->mfaService->getUserMfa($userId);

            if (!$userMfa) {
                $this->logger->warning('[MfaVerifyController] No MFA record found for user ID: {user_id}', ['user_id' => $userId]);
                $this->displayMfaForm(_('No MFA record found. Please contact administrator.'), 'danger');
                return;
            }
        } catch (Exception $e) {
            $this->logger->error('[MfaVerifyController] Error retrieving MFA data: {error}', ['error' => $e->getMessage()]);
            $this->displayMfaForm(_('An error occurred. Please try again.'), 'danger');
            return;
        }

        // Use the MFA service for verification (handles both regular codes and recovery codes)
        $this->logger->debug('[MfaVerifyController] Verifying code for user ID: {user_id}, type: {type}', ['user_id' => $userId, 'type' => $userMfa->getType()]);
        $isValid = $recovered || $this->mfaService->verifyCode($userId, $code);

        $justLocked = $this->recordMfaAttempt($username, $userId, $isValid);

        // Log the verification result
        if ($isValid) {
            $this->logger->info('[MfaVerifyController] Verification successful for user ID: {user_id}', ['user_id' => $userId]);
        } else {
            $this->logger->warning('[MfaVerifyController] Verification failed for user ID: {user_id}', ['user_id' => $userId]);
            // Structured audit entry so fail2ban can react to wrong-code brute force.
            $this->services()->auditService()->logMfaFailed($userMfa->getType());
        }

        if ($isValid) {
            // After successful verification, update the MFA secret for both app and email based auth
            try {
                // Get the user's email from session if available (for email-based MFA)
                $email = $this->userContextService->getSessionData(SessionKeys::EMAIL);

                // Update the MFA secret only for email-based MFA (app-based MFA must keep the same secret)
                $mfaType = $this->mfaService->getMfaType($userId);
                $this->mfaService->updateMfaSecretAfterLogin($userId, $email);

                if ($mfaType === 'email') {
                    $this->logger->info('[MfaVerifyController] Email verification code updated after successful login for user ID: {user_id}', ['user_id' => $userId]);
                } else {
                    $this->logger->info('[MfaVerifyController] Successfully verified app-based MFA for user ID: {user_id}', ['user_id' => $userId]);
                }
            } catch (Exception $e) {
                $this->logger->error('[MfaVerifyController] Error updating MFA secret: {error}', ['error' => $e->getMessage()]);
                // Continue with authentication even if updating the secret fails
            }

            // Promote pending session variables to actual ones now that MFA is verified
            $hadPendingUserId = $this->userContextService->hasSessionData(SessionKeys::PENDING_USERID);
            $sessionPromotionService = new SessionPromotionService($this->userContextService);
            $sessionPromotionService->promotePendingSession();
            if ($hadPendingUserId) {
                $this->logger->debug('[MfaVerifyController] Promoted pending_userid to userid for user ID: {user_id}', ['user_id' => $userId]);
            }

            // Use the centralized session manager to mark MFA as verified
            MfaSessionManager::setMfaVerified();

            $this->services()->auditService()->logMfaVerify($this->mfaService->getMfaType($userId) ?? 'unknown');

            // Populate LDAP authentication cache for LDAP users (if auth_used is ldap)
            // This ensures LDAP+MFA users benefit from session caching
            if (
                $this->userContextService->hasSessionData(SessionKeys::AUTH_USED) &&
                $this->userContextService->getSessionData(SessionKeys::AUTH_USED) === 'ldap'
            ) {
                $ipAddress = $this->client->ip ?: '0.0.0.0';
                $username = $this->userContextService->getLoggedInUsername();

                $this->userContextService->setSessionData(SessionKeys::LDAP_AUTH_TIMESTAMP, time());
                $this->userContextService->setSessionData(SessionKeys::LDAP_AUTH_IP, $ipAddress);
                $this->userContextService->setSessionData(SessionKeys::LDAP_AUTH_USERNAME, $username);
            }

            // Ensure session is written before redirecting
            session_write_close();

            // Clear output buffer if any exists
            if (ob_get_level()) {
                ob_end_clean();
            }

            // Handle AJAX requests
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'redirect' => 'index.php']);
                exit;
            }

            // Send redirect with proper cache headers
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
            header("Location: " . $baseUrlPrefix . "/", true, 302);
            exit;
        } else {
            // MFA verification failed
            $this->displayMfaForm(
                $justLocked
                    ? _('Too many failed attempts. Please try again later.')
                    : _('Invalid verification code. Please try again.'),
                'danger'
            );
        }
    }

    /**
     * Whether the second factor is currently refusing attempts for this account.
     *
     * The MFA stage is counted separately from the password stage, so second-factor
     * failures never block a later password login and a fresh first-factor success
     * cannot reset the MFA counter mid-attack.
     */
    private function isMfaThrottled(string $username, int $userId): bool
    {
        return $this->loginAttemptService->isAccountLocked(
            $username,
            $this->client->ip,
            LoginAttemptService::STAGE_MFA,
            $userId
        );
    }

    /**
     * Counts one second-factor attempt and reports whether it tripped the limit.
     *
     * On tripping, the pending email code is burned so it stays dead even when the
     * lockout window is configured shorter than the code lifetime.
     */
    private function recordMfaAttempt(string $username, int $userId, bool $isValid): bool
    {
        $this->loginAttemptService->recordAttempt(
            $username,
            $this->client->ip,
            $isValid,
            LoginAttemptService::STAGE_MFA,
            $userId
        );

        if ($isValid || !$this->isMfaThrottled($username, $userId)) {
            return false;
        }

        $this->mfaService->invalidatePendingEmailCode($userId);
        return true;
    }

    private function displayMfaForm(?string $message = null, ?string $type = null): void
    {
        // During MFA verification, userid is stored as pending_userid to prevent API bypass
        $userId = $this->userContextService->getLoggedInUserId() ?? $this->userContextService->getSessionData(SessionKeys::PENDING_USERID) ?? 0;
        $username = $this->userContextService->getLoggedInUsername() ?? '';
        $email = $this->userContextService->getSessionData(SessionKeys::EMAIL) ?? $this->userContextService->getSessionData(SessionKeys::PENDING_EMAIL) ?? '';

        // Generate a new CSRF token
        $mfaToken = $this->csrfTokenService->generateToken();
        $this->userContextService->setSessionData(AuthFlowSessionKeys::MFA_TOKEN, $mfaToken);

        // Get MFA type
        $mfaType = $this->mfaService->getMfaType($userId) ?? 'app';

        // A locked account must not trigger the refresh below: the code was just
        // invalidated on hitting the limit, and refresh treats a used code as a
        // reason to mail a new one, which would turn the lockout page into a
        // mail flood aimed at the account owner.
        $mfaLocked = $this->isMfaThrottled($username, (int)$userId);

        // For email-based MFA, check if we need to refresh the code
        if ($mfaType === 'email' && !empty($email) && !$mfaLocked) {
            // First check if mail service is enabled - only required for email verification
            if (!$this->config->get('mail', 'enabled', false)) {
                // Force user to use recovery codes since email is not available
                $message = _('Email verification is not available because mail service is disabled. Please use a recovery code.');
                $type = 'warning';
                $this->logger->warning('[MfaVerifyController] Email verification unavailable - mail service disabled for user ID: {user_id}', ['user_id' => $userId]);
            } else {
                try {
                    // Check if the code needs refreshing (expired or used)
                    $newCode = $this->mfaService->refreshEmailVerificationCodeIfNeeded($userId, $email);

                    if ($newCode !== null) {
                        // A new code was generated
                        $message = _('A new verification code has been sent to your email.');
                        $type = 'info';
                        $this->logger->info('[MfaVerifyController] New email verification code sent for user ID: {user_id}', ['user_id' => $userId]);
                    }
                } catch (RuntimeException $e) {
                    // Mail configuration error occurred
                    $message = $e->getMessage() . ' ' . _('Please use a recovery code instead.');
                    $type = 'warning';
                    $this->logger->error('[MfaVerifyController] Email verification code refresh failed: {error}', ['error' => $e->getMessage()]);
                } catch (Exception $e) {
                    // Other error occurred
                    $message = _('Could not send verification code to your email. Please use a recovery code instead.');
                    $type = 'warning';
                    $this->logger->error('[MfaVerifyController] Email verification error: {error}', ['error' => $e->getMessage()]);
                }
            }
        }

        // Get recovery code length for template validation
        $recoveryCodeLength = (int)$this->config->get('security', 'mfa.recovery_code_length', 10);

        // Use the standard render - the template will hide navigation based on the current_page
        $this->render('mfa_verify.html', [
            'username' => $username,
            'mfa_token' => $mfaToken,
            'mfa_type' => $mfaType,
            'msg' => $message,
            'type' => $type,
            'recovery_code_length' => $recoveryCodeLength
        ]);
    }
}
