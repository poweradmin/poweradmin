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

namespace Poweradmin\Application\Service\Factory;

use PDO;
use Poweradmin\Application\Http\ClientContext;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\Application\Service\MfaVerificationMailer;
use Poweradmin\Application\Service\OidcConfigurationService;
use Poweradmin\Application\Service\RecaptchaService;
use Poweradmin\Application\Service\SamlConfigurationService;
use Poweradmin\Application\Service\UrlService;
use Poweradmin\Domain\Repository\ApiKeyRepositoryInterface;
use Poweradmin\Domain\Repository\PasswordResetTokenRepositoryInterface;
use Poweradmin\Domain\Repository\UserMfaRepositoryInterface;
use Poweradmin\Domain\Repository\UsernameRecoveryRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiKeyService;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\AuditLogWriter;
use Poweradmin\Infrastructure\Logger\DbApiLogger;
use Poweradmin\Infrastructure\Repository\DbApiKeyRepository;
use Poweradmin\Infrastructure\Repository\DbPasswordResetTokenRepository;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;
use Poweradmin\Infrastructure\Repository\DbUsernameRecoveryRepository;
use Poweradmin\Application\Service\Auth\ApiKeyAuthenticationMiddleware;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Application\Service\Auth\BasicAuthenticationMiddleware;
use Poweradmin\Infrastructure\Service\RedirectService;
use Poweradmin\Infrastructure\Session\SessionService;
use Poweradmin\Infrastructure\Utility\ProtocolDetector;
use Psr\Log\LoggerInterface;

/**
 * Sessions, credentials, MFA, API keys and the audit trail. The session,
 * redirect and authentication services are one instance per request.
 */
final class AuthServices
{
    private PDO $db;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private ControllerServiceFactory $services;

    private ?AuditService $auditService = null;
    private ?ClientContext $clientContext = null;
    private ?UrlService $urlService = null;
    private ?ApiKeyRepositoryInterface $apiKeyRepository = null;
    private ?UserMfaRepositoryInterface $userMfaRepository = null;
    private ?MfaService $mfaService = null;
    private ?SessionService $sessionService = null;
    private ?RedirectService $redirectService = null;
    private ?AuthenticationService $authenticationService = null;
    private ?MailService $mailService = null;
    private ?SamlConfigurationService $samlConfigurationService = null;
    private ?OidcConfigurationService $oidcConfigurationService = null;
    private ?RecaptchaService $recaptchaService = null;
    private ?LoginAttemptService $loginAttemptService = null;

    public function __construct(PDO $db, ConfigurationInterface $config, LoggerInterface $logger, ControllerServiceFactory $services)
    {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
        $this->services = $services;
    }

    public function sessionService(): SessionService
    {
        return $this->sessionService ??= new SessionService();
    }

    public function redirectService(): RedirectService
    {
        return $this->redirectService ??= new RedirectService();
    }

    /**
     * Shared login/logout redirect handling for the session-backed controllers
     * and the OIDC and SAML flows they hand off to.
     */
    public function authenticationService(): AuthenticationService
    {
        return $this->authenticationService ??= new AuthenticationService($this->sessionService(), $this->redirectService(), $this->config);
    }

    /**
     * The requesting client, resolved once so every consumer logs the same
     * address and user agent.
     */
    public function clientContext(): ClientContext
    {
        return $this->clientContext ??= ClientContext::fromServer($_SERVER, $this->config);
    }

    /**
     * Absolute URLs for emails and redirects; the protocol comes from the
     * request environment when no application_url is configured.
     */
    public function urlService(): UrlService
    {
        return $this->urlService ??= new UrlService($this->config, new ProtocolDetector(), $this->logger);
    }

    public function userMfaRepository(): UserMfaRepositoryInterface
    {
        return $this->userMfaRepository ??= new DbUserMfaRepository($this->db, $this->config);
    }

    public function mfaService(): MfaService
    {
        return $this->mfaService ??= new MfaService(
            $this->userMfaRepository(),
            $this->config,
            new MfaVerificationMailer($this->mailService(), $this->config),
            null,
            $this->services->userTimezoneService()
        );
    }

    /**
     * One mail transport per request, shared by MFA, password reset, username
     * recovery and the zone-access notifications.
     */
    public function mailService(): MailService
    {
        return $this->mailService ??= new MailService($this->config, $this->logger);
    }

    public function samlConfigurationService(): SamlConfigurationService
    {
        return $this->samlConfigurationService ??= new SamlConfigurationService($this->config, $this->logger);
    }

    public function oidcConfigurationService(): OidcConfigurationService
    {
        return $this->oidcConfigurationService ??= new OidcConfigurationService($this->config, $this->logger);
    }

    public function recaptchaService(): RecaptchaService
    {
        return $this->recaptchaService ??= new RecaptchaService($this->config);
    }

    public function loginAttemptService(): LoginAttemptService
    {
        return $this->loginAttemptService ??= new LoginAttemptService($this->db, $this->config);
    }

    public function apiKeyRepository(): ApiKeyRepositoryInterface
    {
        return $this->apiKeyRepository ??= new DbApiKeyRepository($this->db, $this->config);
    }

    public function apiKeyService(): ApiKeyService
    {
        return new ApiKeyService(
            $this->apiKeyRepository(),
            $this->services->userRepository(),
            $this->config,
            $this->services->permissionService(),
            $this->services->actor(),
            new UserContextService()
        );
    }

    public function apiKeyAuthenticationMiddleware(): ApiKeyAuthenticationMiddleware
    {
        return new ApiKeyAuthenticationMiddleware($this->apiKeyService(), $this->config);
    }

    public function basicAuthenticationMiddleware(): BasicAuthenticationMiddleware
    {
        return new BasicAuthenticationMiddleware($this->db, $this->config);
    }

    public function passwordResetTokenRepository(): PasswordResetTokenRepositoryInterface
    {
        return new DbPasswordResetTokenRepository($this->db, $this->config);
    }

    public function usernameRecoveryRepository(): UsernameRecoveryRepositoryInterface
    {
        return new DbUsernameRecoveryRepository($this->db, $this->config);
    }

    public function auditService(): AuditService
    {
        return $this->auditService ??= new AuditService($this->auditLogWriter(), $this->clientContext(), $this->services->actor());
    }

    public function auditLogWriter(): AuditLogWriter
    {
        return new AuditLogWriter($this->db, $this->config);
    }

    public function apiLogger(): DbApiLogger
    {
        return new DbApiLogger($this->db);
    }
}
