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
use Poweradmin\Infrastructure\Session\FlashMessage;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Application\Service\Web\RedirectService;
use Poweradmin\Infrastructure\Session\SessionService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Starts or ends a session with a flash message, then redirects to login; API requests get a 401 JSON instead.
 */
class AuthenticationService
{
    private SessionService $sessionService;
    private RedirectService $redirectService;
    private ConfigurationInterface $config;

    public function __construct(SessionService $sessionService, RedirectService $redirectService, ConfigurationInterface $config)
    {
        $this->sessionService = $sessionService;
        $this->redirectService = $redirectService;
        $this->config = $config;
    }

    public function logout(FlashMessage $sessionEntity): void
    {
        $this->sessionService->endSession();
        $this->sessionService->setSessionData($sessionEntity);
        $this->redirectToLogin();
    }

    public function auth(FlashMessage $sessionEntity): void
    {
        $this->sessionService->startSession($sessionEntity);
        $this->redirectToLogin();
    }

    private function redirectToLogin(): void
    {
        // Internal API routes are session-authenticated but expect JSON. Bouncing
        // them to the HTML login page gives clients a 302 to parse instead of an
        // auth error, so answer unauthenticated API requests with a 401 JSON body.
        if ($this->isApiRequest()) {
            $this->redirectService->send($this->apiUnauthorizedResponse());
            return;
        }

        $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
        $this->redirectService->redirectTo($baseUrlPrefix . '/login');
    }

    private function isApiRequest(): bool
    {
        // Trailing slash required: only requests below a real API root get the
        // JSON 401; a bare /api/v2 still falls through to the login redirect
        return RequestContext::isApiRequest(requireTrailingSlash: true);
    }

    /**
     * The 401 body an unauthenticated API request receives in place of the login redirect.
     */
    public function apiUnauthorizedResponse(): JsonResponse
    {
        return new JsonResponse(['error' => true, 'message' => 'Unauthorized'], 401);
    }

    public function redirectToIndex(): void
    {
        $baseUrlPrefix = $this->config->get('interface', 'base_url_prefix', '');
        $this->redirectService->redirectTo($baseUrlPrefix . '/');
    }
}
