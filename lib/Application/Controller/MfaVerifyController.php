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

namespace Poweradmin\Application\Controller;

use Exception;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\MfaSessionManager;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use RuntimeException;

class MfaVerifyController extends BaseController
{
    private MfaService $mfaService;
    private CsrfTokenService $csrfTokenService;
    private UserContextService $userContextService;
    private LegacyLogger $auditLogger;
    private IpAddressRetriever $ipAddressRetriever;
    private LoginAttemptService $loginAttemptService;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request, false);

        $this->request = new Request();

        $userMfaRepository = new DbUserMfaRepository($this->db, $this->config);
        $mailService = new MailService($this->config);
        $this->mfaService = new MfaService($userMfaRepository, $this->config, $mailService, null, $this->createUserTimezoneService());

        $this->csrfTokenService = new CsrfTokenService();
        $this->userContextService = new UserContextService();
        $this->auditLogger = new LegacyLogger($this->db);
        $this->ipAddressRetriever = new IpAddressRetriever($_SERVER);
        $this->loginAttemptService = new LoginAttemptService($this->db, $this->config);
    }

    public function run(): void
    {
        // Check if MFA is globally enabled or this is a logout request
        $logout = $this->request->getQueryParam('logout');
        if (!$this->config->get('security', 'mfa.enabled', false) || $logout !== null) {
            // If MFA is disabled or this is a logout request, but we have MFA session flags, clear them
            if ($this->userContextService->hasSessionData(SessionKeys::MFA_REQUIRED)) {
                $this->userContextService->unsetSessionData(SessionKeys::MFA_REQUIRED);
            }

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
        if (!$this->userContextService->getLoggedInUsername() || !$userId || !$this->userContextService->hasSessionData(SessionKeys::MFA_REQUIRED)) {
            $this->redirect('/');
        }

        // If the user is already fully authenticated (MFA passed), redirect to index
        // Use our centralized MFA session manager to check state
        if (!MfaSessionManager::isMfaRequired()) {
            $this->logger->debug('MFA not required according to MfaSessionManager, redirecting to /');
            $this->redirect('/');
        }

        // Make verification more robust by just checking for the code
        if ($this->request->getPostParam('mfa_code') !== null) {
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

        $code = $this->request->getPostParam('mfa_code', '');
        // During MFA verification, userid is stored as pending_userid to prevent API bypass
        $userId = $this->userContextService->getLoggedInUserId() ?? $this->userContextService->getSessionData(SessionKeys::PENDING_USERID);
        $mfaToken = $this->request->getPostParam('mfa_token', '');

        // Validate CSRF token for security
        if (!$this->csrfTokenService->validateToken($mfaToken, SessionKeys::MFA_TOKEN)) {
            $this->logger->warning('[MfaVerifyController] Invalid CSRF token for user ID: {user_id}', ['user_id' => $userId]);
            $this->displayMfaForm(_('Invalid security token. Please try again.'), 'danger');
            return;
        }

        // Brute-force defense: reuses the account_lockout config but tracks the
        // MFA stage separately so second-factor failures cannot block a future
        // password login, and a fresh first-factor success cannot reset the MFA
        // counter mid-attack.
        $username = $this->userContextService->getLoggedInUsername() ?? '';
        $clientIp = $this->ipAddressRetriever->getClientIp();
        if ($username !== '' && $this->loginAttemptService->isAccountLocked($username, $clientIp, LoginAttemptService::STAGE_MFA)) {
            $this->logger->warning('[MfaVerifyController] Account locked, refusing MFA attempt for user ID: {user_id}', ['user_id' => $userId]);
            $this->displayMfaForm(_('Too many failed attempts. Please try again later.'), 'danger');
            return;
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
        $isValid = $this->mfaService->verifyCode($userId, $code);

        // Record the attempt under the MFA stage so it accrues toward the MFA
        // lockout only; first-factor (password) success/failure stays untouched.
        if ($username !== '') {
            $this->loginAttemptService->recordAttempt($username, $clientIp, $isValid, LoginAttemptService::STAGE_MFA);
        }

        // Log the verification result
        if ($isValid) {
            $this->logger->info('[MfaVerifyController] Verification successful for user ID: {user_id}', ['user_id' => $userId]);
        } else {
            $this->logger->warning('[MfaVerifyController] Verification failed for user ID: {user_id}', ['user_id' => $userId]);
            // Structured audit entry so fail2ban can react to wrong-code brute force.
            $this->auditLogger->logWarn(sprintf(
                'client_ip:%s user:%s operation:mfa_failed mfa_type:%s',
                $this->ipAddressRetriever->getClientIp(),
                $this->userContextService->getLoggedInUsername() ?? $_SESSION[SessionKeys::USERLOGIN] ?? 'unknown',
                $userMfa->getType()
            ));
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
            if ($this->userContextService->hasSessionData(SessionKeys::PENDING_USERID)) {
                $this->userContextService->setSessionData(SessionKeys::USERID, $this->userContextService->getSessionData(SessionKeys::PENDING_USERID));
                $this->userContextService->unsetSessionData(SessionKeys::PENDING_USERID);
                $this->logger->debug('[MfaVerifyController] Promoted pending_userid to userid for user ID: {user_id}', ['user_id' => $userId]);
            }
            if ($this->userContextService->hasSessionData(SessionKeys::PENDING_NAME)) {
                $this->userContextService->setSessionData(SessionKeys::NAME, $this->userContextService->getSessionData(SessionKeys::PENDING_NAME));
                $this->userContextService->unsetSessionData(SessionKeys::PENDING_NAME);
            }
            if ($this->userContextService->hasSessionData(SessionKeys::PENDING_EMAIL)) {
                $this->userContextService->setSessionData(SessionKeys::EMAIL, $this->userContextService->getSessionData(SessionKeys::PENDING_EMAIL));
                $this->userContextService->unsetSessionData(SessionKeys::PENDING_EMAIL);
            }
            if ($this->userContextService->hasSessionData(SessionKeys::PENDING_AUTH_USED)) {
                $this->userContextService->setSessionData(SessionKeys::AUTH_USED, $this->userContextService->getSessionData(SessionKeys::PENDING_AUTH_USED));
                $this->userContextService->unsetSessionData(SessionKeys::PENDING_AUTH_USED);
            }
            if ($this->userContextService->hasSessionData(SessionKeys::PENDING_AUTH_METHOD_USED)) {
                $this->userContextService->setSessionData(SessionKeys::AUTH_METHOD_USED, $this->userContextService->getSessionData(SessionKeys::PENDING_AUTH_METHOD_USED));
                $this->userContextService->unsetSessionData(SessionKeys::PENDING_AUTH_METHOD_USED);
            }

            // Promote OIDC-specific pending session variables
            if ($this->userContextService->hasSessionData(SessionKeys::PENDING_OIDC_PROVIDER)) {
                $this->userContextService->setSessionData(SessionKeys::OIDC_PROVIDER, $this->userContextService->getSessionData(SessionKeys::PENDING_OIDC_PROVIDER));
                $this->userContextService->unsetSessionData(SessionKeys::PENDING_OIDC_PROVIDER);
                $this->userContextService->setSessionData(SessionKeys::OIDC_AUTHENTICATED, true);
            }
            if ($this->userContextService->hasSessionData(SessionKeys::PENDING_OIDC_ID_TOKEN)) {
                $this->userContextService->setSessionData(SessionKeys::OIDC_ID_TOKEN, $this->userContextService->getSessionData(SessionKeys::PENDING_OIDC_ID_TOKEN));
                $this->userContextService->unsetSessionData(SessionKeys::PENDING_OIDC_ID_TOKEN);
            }
            if ($this->userContextService->hasSessionData(SessionKeys::PENDING_OAUTH_AVATAR_URL)) {
                $this->userContextService->setSessionData(SessionKeys::OAUTH_AVATAR_URL, $this->userContextService->getSessionData(SessionKeys::PENDING_OAUTH_AVATAR_URL));
                $this->userContextService->unsetSessionData(SessionKeys::PENDING_OAUTH_AVATAR_URL);
            }

            // Promote SAML-specific pending session variables
            if ($this->userContextService->hasSessionData(SessionKeys::PENDING_SAML_PROVIDER)) {
                $this->userContextService->setSessionData(SessionKeys::SAML_PROVIDER, $this->userContextService->getSessionData(SessionKeys::PENDING_SAML_PROVIDER));
                $this->userContextService->unsetSessionData(SessionKeys::PENDING_SAML_PROVIDER);
                $this->userContextService->setSessionData(SessionKeys::SAML_AUTHENTICATED, true);
            }
            if ($this->userContextService->hasSessionData(SessionKeys::PENDING_SAML_NAME_ID)) {
                $this->userContextService->setSessionData(SessionKeys::SAML_NAME_ID, $this->userContextService->getSessionData(SessionKeys::PENDING_SAML_NAME_ID));
                $this->userContextService->unsetSessionData(SessionKeys::PENDING_SAML_NAME_ID);
            }
            if ($this->userContextService->hasSessionData(SessionKeys::PENDING_SAML_SESSION_INDEX)) {
                $this->userContextService->setSessionData(SessionKeys::SAML_SESSION_INDEX, $this->userContextService->getSessionData(SessionKeys::PENDING_SAML_SESSION_INDEX));
                $this->userContextService->unsetSessionData(SessionKeys::PENDING_SAML_SESSION_INDEX);
            }

            // Use the centralized session manager to mark MFA as verified
            MfaSessionManager::setMfaVerified();

            $this->auditLogger->logInfo(sprintf(
                'client_ip:%s user:%s operation:mfa_verify mfa_type:%s',
                $this->ipAddressRetriever->getClientIp(),
                $this->userContextService->getLoggedInUsername() ?? $_SESSION[SessionKeys::USERLOGIN] ?? 'unknown',
                $this->mfaService->getMfaType($userId) ?? 'unknown'
            ));

            // Populate LDAP authentication cache for LDAP users (if auth_used is ldap)
            // This ensures LDAP+MFA users benefit from session caching
            if (
                $this->userContextService->hasSessionData(SessionKeys::AUTH_USED) &&
                $this->userContextService->getSessionData(SessionKeys::AUTH_USED) === 'ldap'
            ) {
                $ipRetriever = new IpAddressRetriever($_SERVER);
                $ipAddress = $ipRetriever->getClientIp() ?: '0.0.0.0';
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
            $this->displayMfaForm(_('Invalid verification code. Please try again.'), 'danger');
        }
    }

    private function displayMfaForm(?string $message = null, ?string $type = null): void
    {
        // During MFA verification, userid is stored as pending_userid to prevent API bypass
        $userId = $this->userContextService->getLoggedInUserId() ?? $this->userContextService->getSessionData(SessionKeys::PENDING_USERID) ?? 0;
        $username = $this->userContextService->getLoggedInUsername() ?? '';
        $email = $this->userContextService->getSessionData(SessionKeys::EMAIL) ?? $this->userContextService->getSessionData(SessionKeys::PENDING_EMAIL) ?? '';

        // Generate a new CSRF token
        $mfaToken = $this->csrfTokenService->generateToken();
        $this->userContextService->setSessionData(SessionKeys::MFA_TOKEN, $mfaToken);

        // Get MFA type
        $mfaType = $this->mfaService->getMfaType($userId) ?? 'app';

        // For email-based MFA, check if we need to refresh the code
        if ($mfaType === 'email' && !empty($email)) {
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
