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
use Poweradmin\Application\Service\MailService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;
use RuntimeException;

class MfaSetupController extends BaseController
{
    private MfaService $mfaService;
    private UserContextService $userContextService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $userMfaRepository = new DbUserMfaRepository($this->db, $this->config);
        $mailService = new MailService($this->config);
        $this->mfaService = new MfaService($userMfaRepository, $this->config, $mailService);
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        // Check if MFA is globally enabled
        if (!$this->config->get('security', 'mfa.enabled', false)) {
            $this->addSystemMessage('error', _('MFA is not enabled on this system.'));
            header("Location: index.php");
            exit;
        }

        // MFA setup forms processing
        if ($this->isPost()) {
            $this->validateCsrfToken();

            if (isset($_POST['setup_app'])) {
                $this->handleAppSetup();
                return;
            }

            if (isset($_POST['verify_app'])) {
                $this->handleAppVerification();
                return;
            }

            if (isset($_POST['setup_email'])) {
                $this->handleEmailSetup();
                return;
            }

            if (isset($_POST['verify_email'])) {
                $this->handleEmailVerification();
                return;
            }

            if (isset($_POST['disable_mfa'])) {
                $this->handleMfaDisable();
                return;
            }

            if (isset($_POST['regenerate_codes'])) {
                $this->handleRegenerateRecoveryCodes();
                return;
            }
        }

        // Display the MFA setup page
        $this->displayMfaSetup();
    }

    private function handleAppSetup(): void
    {
        $userId = $this->userContextService->getLoggedInUserId() ?? 0;

        // Check if MFA is already enabled - use getOrCreate since we're setting up
        $userMfa = $this->mfaService->getOrCreateUserMfa($userId);

        if (!$userMfa) {
            $this->addSystemMessage('error', _('Failed to create MFA record.'));
            $this->displayMfaSetup();
            return;
        }

        if ($userMfa->isEnabled()) {
            $this->addSystemMessage('info', _('MFA is already enabled.'));
            $this->displayMfaSetup();
            return;
        }

        // Generate a new secret if one doesn't exist
        if (!$userMfa->getSecret()) {
            // Generate a proper secret for authenticator apps
            $userMfa->setSecret($this->mfaService->generateSecretKey());
            $userMfa->setType(UserMfa::TYPE_APP);
            $this->mfaService->saveUserMfa($userMfa);

            error_log("[MfaSetupController] Generated new secret for app-based MFA for user $userId");
        }

        // Display verification page
        $this->displayAppVerification($userMfa->getSecret());
    }

    private function handleAppVerification(): void
    {
        $userId = $this->userContextService->getLoggedInUserId() ?? 0;
        $code = $_POST['verification_code'] ?? '';

        if (empty($code)) {
            $this->addSystemMessage('error', _('Verification code is required.'));
            $this->displayMfaSetup();
            return;
        }

        // Use getOrCreate since we're in the verification process
        $userMfa = $this->mfaService->getOrCreateUserMfa($userId);

        // Verify the code
        if ($this->mfaService->verifyCode($userId, $code)) {
            // Enable MFA
            $this->mfaService->enableMfa($userId);

            // Generate recovery codes if they don't exist
            $recoveryCodes = $userMfa->getRecoveryCodesAsArray();
            if (empty($recoveryCodes)) {
                $recoveryCodes = $this->mfaService->regenerateRecoveryCodes($userId);
            }

            $this->addSystemMessage('success', _('MFA has been enabled successfully.'));
            $this->displayRecoveryCodes($recoveryCodes);
        } else {
            $this->addSystemMessage('error', _('Invalid verification code. Please try again.'));
            $userMfa = $this->mfaService->getUserMfa($userId);
            $this->displayAppVerification($userMfa->getSecret());
        }
    }

    private function handleEmailSetup(): void
    {
        $userId = $this->userContextService->getLoggedInUserId() ?? 0;
        $email = $this->userContextService->getSessionData('email') ?? '';

        // Check if user has an email address set
        if (empty($email)) {
            $this->addSystemMessage('error', _('Email address is not available. Please update your email address in your profile.'));
            $this->displayMfaSetup();
            return;
        }

        // Check if mail service is enabled globally
        if (!$this->config->get('mail', 'enabled', false)) {
            $this->addSystemMessage('error', _('Email verification method is not available because mail service is not enabled on this system.'));
            $this->displayMfaSetup();
            return;
        }

        // Check if email MFA is specifically enabled in security settings
        if (!$this->config->get('security', 'mfa.email_enabled', true)) {
            $this->addSystemMessage('error', _('Email verification method is not enabled on this system.'));
            $this->displayMfaSetup();
            return;
        }

        // Use getOrCreate since we're setting up MFA
        $userMfa = $this->mfaService->getOrCreateUserMfa($userId);

        if (!$userMfa) {
            $this->addSystemMessage('error', _('Failed to create MFA record.'));
            $this->displayMfaSetup();
            return;
        }

        // Check if MFA is already enabled
        if ($userMfa->isEnabled()) {
            $this->addSystemMessage('info', _('MFA is already enabled.'));
            $this->displayMfaSetup();
            return;
        }

        // Generate a secret if needed
        if (!$userMfa->getSecret()) {
            $userMfa->setSecret($this->mfaService->generateSecretKey());
            $userMfa->setType(UserMfa::TYPE_EMAIL);
            $this->mfaService->saveUserMfa($userMfa);
        }

        try {
            // Send verification code via email
            $this->mfaService->sendEmailVerificationCode($userId, $email);

            // Display email verification form
            $this->displayEmailVerification($email);
        } catch (RuntimeException $e) {
            // Handle mail configuration issues
            $this->addSystemMessage('error', $e->getMessage());
            $this->displayMfaSetup();
            return;
        } catch (Exception $e) {
            // Handle other errors
            error_log("[MfaSetupController] Email verification error: " . $e->getMessage());
            $this->addSystemMessage('error', _('Failed to send verification code. Please try again later or use app-based authentication.'));
            $this->displayMfaSetup();
            return;
        }
    }

    private function displayEmailVerification(string $email): void
    {
        $this->render('mfa_verify_email.html', [
            'email' => $email
        ]);
    }

    private function handleEmailVerification(): void
    {
        $userId = $this->userContextService->getLoggedInUserId() ?? 0;
        $code = $_POST['verification_code'] ?? '';

        if (empty($code)) {
            $this->addSystemMessage('error', _('Verification code is required.'));
            $this->displayMfaSetup();
            return;
        }

        // Get user MFA record - use getOrCreate since we're in verification
        $this->mfaService->getOrCreateUserMfa($userId);

        // Use the MfaService to verify the code instead of direct comparison
        $isValid = $this->mfaService->verifyCode($userId, $code);

        if ($isValid) {
            // Enable MFA
            $this->mfaService->enableMfa($userId, UserMfa::TYPE_EMAIL);

            // Generate recovery codes
            $recoveryCodes = $this->mfaService->regenerateRecoveryCodes($userId);

            $this->addSystemMessage('success', _('MFA has been enabled with email verification.'));
            $this->displayRecoveryCodes($recoveryCodes);
        } else {
            $this->addSystemMessage('error', _('Invalid verification code. Please try again.'));
            $this->displayEmailVerification($this->userContextService->getSessionData('email') ?? '');
        }
    }

    private function handleMfaDisable(): void
    {
        $userId = $this->userContextService->getLoggedInUserId() ?? 0;

        // Check if MFA exists before trying to disable it
        $userMfa = $this->mfaService->getUserMfa($userId);

        if (!$userMfa) {
            $this->addSystemMessage('info', _('MFA is not enabled.'));
            $this->displayMfaSetup();
            return;
        }

        // Disable MFA
        $this->mfaService->disableMfa($userId);

        $this->addSystemMessage('success', _('MFA has been disabled.'));
        $this->displayMfaSetup();
    }

    /**
     * Handle regeneration of recovery codes
     */
    private function handleRegenerateRecoveryCodes(): void
    {
        $userId = $this->userContextService->getLoggedInUserId() ?? 0;

        // Check if MFA is enabled for this user
        $userMfa = $this->mfaService->getUserMfa($userId);

        if (!$userMfa || !$userMfa->isEnabled()) {
            $this->addSystemMessage('error', _('MFA must be enabled to regenerate recovery codes.'));
            $this->displayMfaSetup();
            return;
        }

        // Generate new recovery codes
        $recoveryCodes = $this->mfaService->regenerateRecoveryCodes($userId);

        $this->addSystemMessage('success', _('Recovery codes have been regenerated. Please save them in a safe place.'));
        $this->displayRecoveryCodes($recoveryCodes);
    }

    private function displayMfaSetup(): void
    {
        $userId = $this->userContextService->getLoggedInUserId() ?? 0;
        $userMfa = $this->mfaService->getUserMfa($userId);

        // Default values if no MFA record exists yet
        $mfaEnabled = false;
        $mfaType = UserMfa::TYPE_APP; // Default type

        if ($userMfa) {
            $mfaEnabled = $userMfa->isEnabled();
            $mfaType = $userMfa->getType();
        }

        // Check if mail service is enabled for the template
        $mailEnabled = $this->config->get('mail', 'enabled', false);
        // Check if email MFA is specifically enabled
        $emailMfaEnabled = $this->config->get('security', 'mfa.email_enabled', true);

        $this->render('mfa_setup.html', [
            'mfa_enabled' => $mfaEnabled,
            'mfa_type' => $mfaType,
            'email' => $this->userContextService->getSessionData('email') ?? '',
            'mail_enabled' => $mailEnabled && $emailMfaEnabled
        ]);
    }

    private function displayAppVerification(string $secret): void
    {
        // Get the user's email or username for the authenticator app
        $email = $this->userContextService->getSessionData('email') ?? '';
        $userId = $this->userContextService->getLoggedInUserId() ?? 0;

        // Make sure we have a valid email for the QR code
        if (empty($email)) {
            error_log("[MfaSetupController] Warning: Empty email when generating QR code for user $userId");
            // Use a generic username if email is missing
            $email = "user$userId";
        }

        // Generate the QR code with proper email and secret
        $qrCode = $this->mfaService->generateQrCodeSvg($email, $secret);

        // Log QR code generation for debugging
        error_log("[MfaSetupController] Generated QR code for user ID: $userId");

        // Render the template with all necessary data
        $this->render('mfa_verify_app.html', [
            'secret' => $secret,
            'qr_code' => $qrCode,
            'email' => $email
        ]);
    }

    private function displayRecoveryCodes(array $recoveryCodes): void
    {
        $userId = $this->userContextService->getLoggedInUserId() ?? 0;
        $userMfa = $this->mfaService->getOrCreateUserMfa($userId);

        $this->render('mfa_recovery_codes.html', [
            'recovery_codes' => $recoveryCodes,
            'mfa_enabled' => $userMfa->isEnabled(),
            'mfa_type' => $userMfa->getType()
        ]);
    }
}
