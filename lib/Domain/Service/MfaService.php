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

namespace Poweradmin\Domain\Service;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Exception;
use PDOException;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Application\Service\EmailTemplateService;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Repository\UserMfaRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use PragmaRX\Google2FA\Exceptions\IncompatibleWithGoogleAuthenticatorException;
use PragmaRX\Google2FA\Exceptions\InvalidCharactersException;
use PragmaRX\Google2FA\Exceptions\SecretKeyTooShortException;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class MfaService
{
    private Google2FA $google2fa;
    private UserMfaRepositoryInterface $userMfaRepository;
    private ConfigurationManager $configManager;
    private MailService $mailService;
    private EmailTemplateService $templateService;

    /**
     * MfaService constructor
     */
    public function __construct(
        UserMfaRepositoryInterface $userMfaRepository,
        ConfigurationManager $configManager,
        MailService $mailService
    ) {
        $this->google2fa = new Google2FA();
        $this->userMfaRepository = $userMfaRepository;
        $this->configManager = $configManager;
        $this->mailService = $mailService;
        $this->templateService = new EmailTemplateService($configManager);
    }

    /**
     * Get the user MFA repository
     *
     * @return UserMfaRepositoryInterface
     */
    public function getUserMfaRepository(): UserMfaRepositoryInterface
    {
        return $this->userMfaRepository;
    }

    /**
     * Save a UserMfa object
     *
     * @param UserMfa $userMfa The UserMfa object to save
     * @return UserMfa The saved UserMfa object
     */
    public function saveUserMfa(UserMfa $userMfa): UserMfa
    {
        return $this->userMfaRepository->save($userMfa);
    }

    /**
     * Get user's MFA settings
     *
     * This method only retrieves the MFA settings and does not create a new record.
     *
     * @param int $userId User ID
     * @return UserMfa|null MFA settings or null if not found
     */
    public function getUserMfa(int $userId): ?UserMfa
    {
        try {
            return $this->userMfaRepository->findByUserId($userId);
        } catch (PDOException $e) {
            // If the table doesn't exist or there's another database error, log the error and return null
            error_log("getUserMfa failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get or create MFA settings for a user
     *
     * This method will create a new MFA record if one doesn't exist.
     *
     * @param int $userId User ID
     * @return UserMfa|null MFA settings or null on error
     */
    public function getOrCreateUserMfa(int $userId): ?UserMfa
    {
        try {
            // First, attempt to find an existing record
            $userMfa = $this->userMfaRepository->findByUserId($userId);

            // If found, return it
            if ($userMfa) {
                return $userMfa;
            }

            // Double-check that the record still doesn't exist to avoid race conditions
            $userMfa = $this->userMfaRepository->findByUserId($userId);
            if ($userMfa) {
                return $userMfa;
            }

            // Create and save a new record
            error_log("Creating new MFA record for user $userId");
            $userMfa = UserMfa::create($userId);

            // The repository's save method will handle duplicate key errors by retrieving
            // the existing record if one was created in the meantime
            return $this->userMfaRepository->save($userMfa);
        } catch (PDOException $e) {
            // If the table doesn't exist or there's another database error, log the error and return null
            error_log("getOrCreateUserMfa failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate a new secret key for authenticator app-based MFA
     *
     * This method generates a proper Base32-encoded secret key
     * that is compatible with Google Authenticator, Microsoft Authenticator,
     * and other TOTP apps
     *
     * @param int $length Length of the secret key in bytes (16 is standard)
     * @return string Base32-encoded secret key
     * @throws IncompatibleWithGoogleAuthenticatorException
     * @throws InvalidCharactersException
     * @throws SecretKeyTooShortException
     */
    public function generateSecretKey(int $length = 16): string
    {
        // Use Google2FA's built-in method which generates a Base32-encoded secret
        $secret = $this->google2fa->generateSecretKey($length);

        return $secret;
    }

    /**
     * Check if a secret key is valid for TOTP authentication
     *
     * This method checks if a secret key meets the requirements for
     * use with TOTP authenticator apps (proper Base32 encoding)
     *
     * @param string|null $secret The secret key to check
     * @return bool True if the secret is valid, false otherwise
     */
    public function isValidTotpSecret(?string $secret): bool
    {
        if ($secret === null || empty($secret)) {
            return false;
        }

        // Check if the secret is Base32-encoded (A-Z and 2-7)
        return (bool)preg_match('/^[A-Z2-7]+$/', $secret);
    }

    /**
     * Generate a simple numeric verification code for email-based MFA
     *
     * @return string A 6-digit verification code
     */
    public function generateEmailVerificationCode(): string
    {
        // Generate a simple numeric verification code (6 digits)
        $numericCode = mt_rand(100000, 999999);
        // Convert to string explicitly
        return (string)$numericCode;
    }

    /**
     * Enable MFA for a user
     */
    public function enableMfa(int $userId, string $type = UserMfa::TYPE_APP): UserMfa
    {
        $userMfa = $this->getUserMfa($userId);

        if (!$userMfa->getSecret()) {
            $userMfa->setSecret($this->generateSecretKey());
        }

        $userMfa->setType($type);
        $userMfa->enable();

        // Generate recovery codes if they don't exist
        if (empty($userMfa->getRecoveryCodesAsArray())) {
            // Get configuration values for recovery codes
            $codeCount = (int)$this->configManager->get('security', 'mfa.recovery_codes', 10);
            $codeLength = (int)$this->configManager->get('security', 'mfa.recovery_code_length', 10);

            // Convert length from character length to byte length (divide by 2 since hex encoding doubles length)
            $byteLength = (int)ceil($codeLength / 2);

            $userMfa->generateRecoveryCodes($codeCount, $byteLength);
        }

        return $this->userMfaRepository->save($userMfa);
    }

    /**
     * Disable MFA for a user
     *
     * @param int $userId The user ID
     * @return UserMfa|null The updated MFA settings or null if not found
     */
    public function disableMfa(int $userId): ?UserMfa
    {
        $userMfa = $this->getUserMfa($userId);

        if (!$userMfa) {
            error_log("Cannot disable MFA: No MFA record found for user $userId");
            return null;
        }

        $userMfa->disable();
        return $this->userMfaRepository->save($userMfa);
    }

    /**
     * Verify a TOTP code, email code, or recovery code for a user
     *
     * @param int $userId The user ID
     * @param string $code The verification code to check
     * @return bool True if the code is valid, false otherwise
     */
    public function verifyCode(int $userId, string $code): bool
    {
        $userMfa = $this->userMfaRepository->findByUserId($userId);

        if (!$userMfa || !$userMfa->getSecret()) {
            error_log("[MfaService] Verification failed - User not found or no secret");
            return false;
        }

        $mfaType = $userMfa->getType();
        $secret = $userMfa->getSecret();

        // For app-based authentication, verify the secret is in the correct format
        if ($mfaType === UserMfa::TYPE_APP && !$this->isValidTotpSecret($secret)) {
            error_log("[MfaService] Invalid secret format for app-based authentication - not Base32 encoded");
            return false;
        }

        error_log("[MfaService] Verifying code for user ID: $userId, type: $mfaType");

        // First, check if the code matches a recovery code
        if ($userMfa->validateRecoveryCode($code)) {
            error_log("[MfaService] Valid recovery code used by user ID: $userId");
            // Recovery code is removed from the list in validateRecoveryCode
            $this->userMfaRepository->save($userMfa);
            return true;
        }

        // For email type, verify with direct comparison
        if ($mfaType === UserMfa::TYPE_EMAIL) {
            // Check if mail service is enabled before proceeding with email verification
            if (!$this->configManager->get('mail', 'enabled', false)) {
                error_log("[MfaService] Email verification attempted but mail service is disabled for user ID: $userId");
                // Only proceed to recovery code check, which was already done above, or TOTP below
                // Do not validate the email code if mail service is disabled
            } else {
                $storedSecret = $userMfa->getSecret();
                $metadataJson = $userMfa->getVerificationData();
                $metadata = null;

                // Check verification metadata if available
                if (!empty($metadataJson)) {
                    try {
                        $metadata = json_decode($metadataJson, true);

                        // Check if code has expired
                        if (isset($metadata['expires_at']) && $metadata['expires_at'] < time()) {
                            error_log("[MfaService] Email code expired for user ID: $userId");
                            return false;
                        }

                        // Check if code was already used
                        if (isset($metadata['used']) && $metadata['used'] === true) {
                            error_log("[MfaService] Email code already used for user ID: $userId");
                            return false;
                        }
                    } catch (Exception $e) {
                        error_log("[MfaService] Error processing metadata: " . $e->getMessage());
                    }
                }

                // Verify the code (trim to handle potential whitespace)
                $isValid = trim($storedSecret) === trim($code);

                if ($isValid) {
                    error_log("[MfaService] Valid email code for user ID: $userId");

                    // Mark the code as used
                    if ($metadata) {
                        $metadata['used'] = true;
                        $userMfa->setVerificationData($metadata);
                    }

                    // Update last used timestamp
                    $userMfa->updateLastUsed();
                    $this->userMfaRepository->save($userMfa);
                    return true;
                } else {
                    error_log("[MfaService] Invalid email code for user ID: $userId");
                    return false;
                }
            }
        }

        // For app-based MFA, verify the TOTP code
        try {
            // Gently clean the verification code - just remove spaces
            $code = str_replace(' ', '', trim($code));

            // Let's use the secret directly as stored - any modification might break compatibility
            $secret = $userMfa->getSecret();

            // Allow for a window of 1 period before and after - 2 might be too permissive
            // This helps if device's clock is slightly out of sync (±30 seconds)
            $window = 1;

            // Use the Google2FA library to verify the TOTP code exactly as intended
            $isValid = $this->google2fa->verifyKey($secret, $code, $window);

            if ($isValid) {
                error_log("[MfaService] Valid TOTP code for user ID: $userId");
                $userMfa->updateLastUsed();
                $this->userMfaRepository->save($userMfa);
            } else {
                error_log("[MfaService] Invalid TOTP code for user ID: $userId");
            }

            return $isValid;
        } catch (Exception $e) {
            error_log("[MfaService] TOTP verification error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate a QR code SVG for MFA setup
     */
    public function generateQrCodeSvg(string $email, string $secret): string
    {
        // Get the application name from configuration to use as the issuer
        $appName = $this->configManager->get('interface', 'title', 'Poweradmin');

        // Let's not modify the secret at all - use it exactly as provided
        // This ensures compatibility with what the verification expects

        // Generate the otpauth URL using the Google2FA library
        $qrCodeUrl = $this->google2fa->getQRCodeUrl($appName, $email, $secret);

        // Make sure the issuer parameter is included in the URL - this is critical for compatibility
        if (strpos($qrCodeUrl, 'issuer=') === false) {
            $qrCodeUrl .= (strpos($qrCodeUrl, '?') !== false ? '&' : '?') . 'issuer=' . urlencode($appName);
        }

        // Log for audit
        error_log("[MfaService] Generated QR code for user with email: " . $email);

        // Create a QR code renderer with increased size for better scanability
        $renderer = new ImageRenderer(
            new RendererStyle(240), // Increased size from 200 to 240
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);
        return $writer->writeString($qrCodeUrl);
    }

    /**
     * Send verification code via email for email-based MFA
     *
     * Generates a new verification code, saves it to the database, and sends it via email.
     * For email-based MFA, the secret field is used to store the most recent verification code.
     * The code is temporary and should be regenerated for each verification attempt.
     *
     * @param int $userId The user ID
     * @param string $email The email address to send the code to
     * @return string The generated verification code
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function sendEmailVerificationCode(int $userId, string $email): string
    {
        // Check if mail service is enabled
        if (!$this->configManager->get('mail', 'enabled', false)) {
            error_log("[MfaService] Email verification attempted but mail service is disabled for user ID: $userId");
            throw new RuntimeException('Email verification is not available because mail service is disabled.');
        }

        // Check if email is empty
        if (empty($email)) {
            error_log("[MfaService] Email verification attempted but email is empty for user ID: $userId");
            throw new RuntimeException('Email address is required for email verification.');
        }

        // Validate mail configuration before proceeding
        if (
            method_exists($this->mailService, 'isMailConfigurationValid') &&
            !$this->mailService->isMailConfigurationValid()
        ) {
            error_log("[MfaService] Email verification attempted but mail configuration is invalid for user ID: $userId");
            throw new RuntimeException('Email verification is not available because mail service is misconfigured or mail server is unreachable.');
        }

        $userMfa = $this->getUserMfa($userId);

        // Generate a verification code
        $verificationCode = $this->generateEmailVerificationCode();

        // Add expiration timestamp (10 minutes from now)
        $expiresAt = time() + 600; // 10 minutes

        // Store metadata about this verification code
        $verificationMeta = json_encode([
            'code' => $verificationCode,
            'generated_at' => time(),
            'expires_at' => $expiresAt,
            'used' => false
        ]);

        // Log the code generation but not the actual code for security
        error_log("[MfaService] Generated verification code for user $userId, expires at " . date('Y-m-d H:i:s', $expiresAt));

        // Store the verification code and related metadata
        $userMfa->setSecret($verificationCode);
        $userMfa->setType(UserMfa::TYPE_EMAIL);

        // Store verification metadata in the verification_data field
        // This keeps it separate from recovery codes
        $userMfa->setVerificationDataRaw($verificationMeta);

        // Save to database
        $this->userMfaRepository->save($userMfa);

        // Log the action
        error_log("New email verification code generated for user {$userId} - expires at " .
            date('Y-m-d H:i:s', $expiresAt));

        // Send the code via email
        $templates = $this->templateService->renderMfaVerificationEmail($verificationCode, $expiresAt);

        $this->mailService->sendMail($email, $templates['subject'], $templates['html'], $templates['text']);

        return $verificationCode;
    }

    /**
     * Generates a new verification code if the existing one has expired
     *
     * This is useful for generating a new code when a user tries to log in again
     * after their previous code has expired.
     *
     * @param int $userId The user ID
     * @param string $email The user's email address
     * @return string|null The new code if generated, null otherwise
     */
    public function refreshEmailVerificationCodeIfNeeded(int $userId, string $email): ?string
    {
        // Check if mail service is enabled
        if (!$this->configManager->get('mail', 'enabled', false)) {
            error_log("[MfaService] Email verification refresh attempted but mail service is disabled for user ID: $userId");
            throw new RuntimeException('Email verification is not available because mail service is disabled.');
        }

        // Check if email is empty
        if (empty($email)) {
            error_log("[MfaService] Email verification refresh attempted but email is empty for user ID: $userId");
            throw new RuntimeException('Email address is required for email verification.');
        }

        // Validate mail configuration before proceeding
        if (
            method_exists($this->mailService, 'isMailConfigurationValid') &&
            !$this->mailService->isMailConfigurationValid()
        ) {
            error_log("[MfaService] Email verification refresh attempted but mail configuration is invalid for user ID: $userId");
            throw new RuntimeException('Email verification is not available because mail service is misconfigured or mail server is unreachable.');
        }

        $userMfa = $this->getUserMfa($userId);

        // Only proceed if this is email-based MFA
        if ($userMfa->getType() !== UserMfa::TYPE_EMAIL) {
            return null;
        }

        // Check if we have metadata
        $metadataJson = $userMfa->getVerificationData();
        $needsRefresh = true;

        if (!empty($metadataJson)) {
            try {
                $metadata = json_decode($metadataJson, true);

                // Check if code has expired or was used
                if (
                    !isset($metadata['expires_at']) ||
                    $metadata['expires_at'] < time() ||
                    (isset($metadata['used']) && $metadata['used'] === true)
                ) {
                    $needsRefresh = true;
                } else {
                    // Code is still valid
                    $needsRefresh = false;
                }
            } catch (Exception $e) {
                error_log("[MfaService] Error checking verification code status: " . $e->getMessage());
                $needsRefresh = true;
            }
        }

        if ($needsRefresh) {
            error_log("[MfaService] Generating new email verification code for user $userId");
            try {
                return $this->sendEmailVerificationCode($userId, $email);
            } catch (Exception $e) {
                error_log("[MfaService] Failed to send verification code: " . $e->getMessage());
                return null;
            }
        }

        return null;
    }

    /**
     * Check if MFA is enabled for a user
     */
    public function isMfaEnabled(int $userId): bool
    {
        try {
            $userMfa = $this->userMfaRepository->findByUserId($userId);
            return $userMfa && $userMfa->isEnabled();
        } catch (PDOException $e) {
            // If the table doesn't exist or there's another database error, assume MFA is disabled
            error_log("MFA check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get MFA type for a user
     */
    public function getMfaType(int $userId): ?string
    {
        try {
            $userMfa = $this->userMfaRepository->findByUserId($userId);
            return $userMfa ? $userMfa->getType() : null;
        } catch (PDOException $e) {
            // If the table doesn't exist or there's another database error, assume null
            error_log("getMfaType failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get recovery codes for a user
     */
    public function getRecoveryCodes(int $userId): array
    {
        $userMfa = $this->userMfaRepository->findByUserId($userId);
        return $userMfa ? $userMfa->getRecoveryCodesAsArray() : [];
    }

    /**
     * Generate new recovery codes for a user
     */
    public function regenerateRecoveryCodes(int $userId): array
    {
        $userMfa = $this->getUserMfa($userId);

        // Get configuration values for recovery codes
        $codeCount = (int)$this->configManager->get('security', 'mfa.recovery_codes', 8);
        $codeLength = (int)$this->configManager->get('security', 'mfa.recovery_code_length', 10);

        // Convert length from character length to byte length (divide by 2 since hex encoding doubles length)
        $byteLength = (int)ceil($codeLength / 2);

        // Generate codes with the configured parameters
        $codes = $userMfa->generateRecoveryCodes($codeCount, $byteLength);
        $this->userMfaRepository->save($userMfa);

        return $codes;
    }

    /**
     * Update MFA secret for a user after successful verification
     *
     * For email-based MFA: Generates a new verification code for security
     * For app-based MFA: Preserves the existing secret (must not change or app codes won't work)
     *
     * @param int $userId The user ID
     * @param string|null $email User's email address (optional, for logging)
     * @return void
     */
    public function updateMfaSecretAfterLogin(int $userId, ?string $email = null): void
    {
        try {
            $userMfa = $this->getUserMfa($userId);

            if (!$userMfa || !$userMfa->isEnabled()) {
                error_log("[MfaService] Cannot update MFA: User $userId has no enabled MFA");
                return;
            }

            $mfaType = $userMfa->getType();

            // We should ONLY update the secret for email-based MFA
            // For app-based MFA, the secret must remain the same or the authenticator app codes won't work!
            if ($mfaType === UserMfa::TYPE_EMAIL) {
                // Only for email-based MFA, generate a new verification code
                $newSecret = $this->generateEmailVerificationCode();
                $userMfa->setSecret($newSecret);
                error_log("[MfaService] Generated new email verification code for user $userId");
            } else {
                // For app-based MFA, DO NOT change the secret
                error_log("[MfaService] App-based MFA secret preserved for user $userId - no changes made");
            }

            // Save the updated user MFA data (for email-based) or just update last used timestamp
            $userMfa->updateLastUsed();
            $this->userMfaRepository->save($userMfa);
        } catch (Exception $e) {
            error_log("[MfaService] Error in MFA update: " . $e->getMessage());
        }
    }
}
