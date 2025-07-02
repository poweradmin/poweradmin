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

use Poweradmin\BaseController;
use Poweradmin\Domain\Service\UserAgreementService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Repository\DbUserAgreementRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;

class UserAgreementController extends BaseController
{
    private UserAgreementService $agreementService;
    private UserContextService $userContextService;

    public function __construct(array $request)
    {
        parent::__construct($request, true);

        $this->agreementService = new UserAgreementService(
            new DbUserAgreementRepository($this->db, $this->config),
            $this->config
        );
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        if (!$this->agreementService->isEnabled()) {
            $this->redirect('index.php');
        }

        if ($this->isPost()) {
            $this->handleAgreementSubmission();
        } else {
            $this->showAgreementForm();
        }
    }

    private function showAgreementForm(): void
    {
        $theme = $this->config->get('interface', 'theme', 'default');
        $templatePath = $this->agreementService->getAgreementTemplate($theme);

        $messages = $this->getMessages('user_agreement');
        $msg = '';
        $type = '';
        if ($messages !== null && count($messages) > 0) {
            $msg = $messages[0]['content'] ?? '';
            $type = $messages[0]['type'] ?? '';
        }

        $this->render($templatePath, [
            'agreement_version' => $this->agreementService->getCurrentVersion(),
            'custom_content_exists' => $this->agreementService->hasCustomContent($theme),
            'msg' => $msg,
            'type' => $type,
        ]);
    }

    private function handleAgreementSubmission(): void
    {
        $this->validateCsrfToken();

        if (!isset($_POST['accept_agreement'])) {
            $this->setMessage('user_agreement', 'danger', 'You must accept the agreement to continue.');
            $this->showAgreementForm();
            return;
        }

        $userId = $this->userContextService->getLoggedInUserId();
        $ipRetriever = new IpAddressRetriever($_SERVER);
        $ipAddress = $ipRetriever->getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if ($this->agreementService->recordAgreementAcceptance($userId, $ipAddress, $userAgent)) {
            $this->setMessage('index', 'success', _('User agreement accepted successfully.'));
            $this->redirect('index.php');
        } else {
            $this->setMessage('user_agreement', 'danger', 'Failed to record agreement acceptance. Please try again.');
            $this->showAgreementForm();
        }
    }
}
