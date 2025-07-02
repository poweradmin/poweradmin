<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\MfaSessionManager;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;
use RuntimeException;

class MfaVerifyController extends BaseController
{
    private MfaService $mfaService;
    private CsrfTokenService $csrfTokenService;
    private UserContextService $userContextService;

    public function __construct(array $request)
    {
        parent::__construct($request, false);

        $userMfaRepository = new DbUserMfaRepository($this->db, $this->config);
        $mailService = new MailService($this->config);
        $this->mfaService = new MfaService($userMfaRepository, $this->config, $mailService);

        $this->csrfTokenService = new CsrfTokenService();
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        // Check if MFA is globally enabled or this is a logout request
        if (!$this->config->get('security', 'mfa.enabled', false) || isset($_GET['logout'])) {
            // If MFA is disabled or this is a logout request, but we have MFA session flags, clear them
            if ($this->userContextService->hasSessionData('mfa_required')) {
                $this->userContextService->unsetSessionData('mfa_required');
            }

            // If this is a logout request, do a proper logout
            if (isset($_GET['logout'])) {
                session_regenerate_id(true);
                session_unset();
                header("Location: index.php?page=login");
            } else {
                // Otherwise just mark as authenticated
                $this->userContextService->setSessionData('authenticated', true);
                session_regenerate_id(true);
                header("Location: index.php");
            }
            exit;
        }

        // Check if we have the necessary session data
        if (!$this->userContextService->getLoggedInUsername() || !$this->userContextService->getLoggedInUserId() || !$this->userContextService->hasSessionData('mfa_required')) {
            header("Location: index.php");
            exit;
        }

        // If the user is already fully authenticated (MFA passed), redirect to index
        // Use our centralized MFA session manager to check state
        if (!MfaSessionManager::isMfaRequired()) {
            error_log("MFA not required according to MfaSessionManager, redirecting to index.php");
            header("Location: index.php");
            exit;
        }

        // Make verification more robust by just checking for the code
        if (isset($_POST['mfa_code'])) {
            $this->handleMfaVerification();
            return;
        }

        // Otherwise, display the MFA form
        $this->displayMfaForm();
    }

    private function handleMfaVerification(): void
    {
        // Basic logging
        error_log("[MfaVerifyController] Verification attempt started");

        $code = $_POST['mfa_code'] ?? '';
        $userId = $this->userContextService->getLoggedInUserId() ?? 0;
        $mfaToken = $_POST['mfa_token'] ?? '';

        // Validate CSRF token for security
        if (!$this->csrfTokenService->validateToken($mfaToken, 'mfa_token')) {
            error_log("[MfaVerifyController] Invalid CSRF token for user ID: $userId");
            $this->displayMfaForm(_('Invalid security token. Please try again.'), 'danger');
            return;
        }

        // Get user MFA record
        try {
            $userMfa = $this->mfaService->getUserMfa($userId);

            if (!$userMfa) {
                error_log("[MfaVerifyController] No MFA record found for user ID: $userId");
                $this->displayMfaForm(_('No MFA record found. Please contact administrator.'), 'danger');
                return;
            }
        } catch (Exception $e) {
            error_log("[MfaVerifyController] Error retrieving MFA data: " . $e->getMessage());
            $this->displayMfaForm(_('An error occurred. Please try again.'), 'danger');
            return;
        }

        // Use the MFA service for verification (handles both regular codes and recovery codes)
        error_log("[MfaVerifyController] Verifying code for user ID: $userId, type: {$userMfa->getType()}");
        $isValid = $this->mfaService->verifyCode($userId, $code);

        // Log the verification result
        if ($isValid) {
            error_log("[MfaVerifyController] Verification successful for user ID: $userId");
        } else {
            error_log("[MfaVerifyController] Verification failed for user ID: $userId");
        }

        if ($isValid) {
            // After successful verification, update the MFA secret for both app and email based auth
            try {
                // Get the user's email from session if available (for email-based MFA)
                $email = $this->userContextService->getSessionData('email');

                // Update the MFA secret only for email-based MFA (app-based MFA must keep the same secret)
                $mfaType = $this->mfaService->getMfaType($userId);
                $this->mfaService->updateMfaSecretAfterLogin($userId, $email);

                if ($mfaType === 'email') {
                    error_log("[MfaVerifyController] Email verification code updated after successful login for user ID: $userId");
                } else {
                    error_log("[MfaVerifyController] Successfully verified app-based MFA for user ID: $userId");
                }
            } catch (Exception $e) {
                error_log("[MfaVerifyController] Error updating MFA secret: " . $e->getMessage());
                // Continue with authentication even if updating the secret fails
            }

            // Use the centralized session manager to mark MFA as verified
            MfaSessionManager::setMfaVerified();

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
            header("Location: index.php", true, 302);
            exit;
        } else {
            // MFA verification failed
            $this->displayMfaForm(_('Invalid verification code. Please try again.'), 'danger');
        }
    }

    private function displayMfaForm(?string $message = null, ?string $type = null): void
    {
        $userId = $this->userContextService->getLoggedInUserId() ?? 0;
        $username = $this->userContextService->getLoggedInUsername() ?? '';
        $email = $this->userContextService->getSessionData('email') ?? '';

        // Generate a new CSRF token
        $mfaToken = $this->csrfTokenService->generateToken();
        $this->userContextService->setSessionData('mfa_token', $mfaToken);

        // Get MFA type
        $mfaType = $this->mfaService->getMfaType($userId) ?? 'app';

        // For email-based MFA, check if we need to refresh the code
        if ($mfaType === 'email' && !empty($email)) {
            // First check if mail service is enabled - only required for email verification
            if (!$this->config->get('mail', 'enabled', false)) {
                // Force user to use recovery codes since email is not available
                $message = _('Email verification is not available because mail service is disabled. Please use a recovery code.');
                $type = 'warning';
                error_log("[MfaVerifyController] Email verification unavailable - mail service disabled for user ID: $userId");
            } else {
                try {
                    // Check if the code needs refreshing (expired or used)
                    $newCode = $this->mfaService->refreshEmailVerificationCodeIfNeeded($userId, $email);

                    if ($newCode !== null) {
                        // A new code was generated
                        $message = _('A new verification code has been sent to your email.');
                        $type = 'info';
                        error_log("[MfaVerifyController] New email verification code sent for user ID: $userId");
                    }
                } catch (RuntimeException $e) {
                    // Mail configuration error occurred
                    $message = $e->getMessage() . ' ' . _('Please use a recovery code instead.');
                    $type = 'warning';
                    error_log("[MfaVerifyController] Email verification code refresh failed: " . $e->getMessage());
                } catch (Exception $e) {
                    // Other error occurred
                    $message = _('Could not send verification code to your email. Please use a recovery code instead.');
                    $type = 'warning';
                    error_log("[MfaVerifyController] Email verification error: " . $e->getMessage());
                }
            }
        }

        // Use the standard render - the template will hide navigation based on the current_page
        $this->render('mfa_verify.html', [
            'username' => $username,
            'mfa_token' => $mfaToken,
            'mfa_type' => $mfaType,
            'msg' => $message,
            'type' => $type
        ]);
    }
}
