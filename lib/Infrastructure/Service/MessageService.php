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

namespace Poweradmin\Infrastructure\Service;

use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Domain\Service\UserContextService;

/**
 * Queues per-page flash messages in the session and renders the bare-HTML fatal error page.
 */
class MessageService
{
    private const TYPE_ERROR = 'error';

    private UserContextService $userContextService;

    public function __construct(?UserContextService $userContextService = null)
    {
        $this->userContextService = $userContextService ?? new UserContextService();
    }

    /**
     * Add a message to be displayed for a specific script
     * Prevents duplicate messages from being added
     *
     * @param string $script The script to set the message for
     * @param string $type The type of message (error, warning, success, info)
     * @param string $content The content of the message
     * @param string|null $recordName Optional record name for context
     */
    public function addMessage(string $script, string $type, string $content, ?string $recordName = null): void
    {
        if ($recordName !== null) {
            $content = sprintf('%s (Record: %s)', $content, $recordName);
        }

        $newMessage = [
            'type' => $type,
            'content' => $content
        ];

        $messages = $this->userContextService->getSessionData(SessionKeys::MESSAGES) ?? [];
        if (!isset($messages[$script])) {
            $messages[$script] = [];
        }

        // Check if this message already exists to prevent duplicates
        foreach ($messages[$script] as $existingMessage) {
            if ($existingMessage['type'] === $type && $existingMessage['content'] === $content) {
                return;
            }
        }

        $messages[$script][] = $newMessage;
        $this->userContextService->setSessionData(SessionKeys::MESSAGES, $messages);
    }

    /**
     * Get messages for a specific script and clear them from session
     *
     * @param string $script The script to get messages for
     * @return array|null The messages for the script, or null if no messages are set
     */
    public function getMessages(string $script): ?array
    {
        $messages = $this->userContextService->getSessionData(SessionKeys::MESSAGES) ?? [];
        if (!isset($messages[$script])) {
            return null;
        }

        $scriptMessages = $messages[$script];
        unset($messages[$script]);
        $this->userContextService->setSessionData(SessionKeys::MESSAGES, $messages);

        return $scriptMessages;
    }

    /**
     * Add a system error message that will be displayed in the proper place in the HTML
     *
     * @param string $error The error message to display
     * @param string|null $recordName Optional record name for context
     */
    public function addSystemError(string $error, ?string $recordName = null): void
    {
        $this->addMessage('system', self::TYPE_ERROR, $error, $recordName);
    }

    /**
     * Display a system error directly with basic HTML
     * Useful for critical errors before the header/footer can be rendered
     *
     * @param string $error The error message to display
     * @param bool $exit False only in tests, which cannot survive the exit
     */
    public function displayDirectSystemError(string $error, bool $exit = true): void
    {
        $this->addSystemError($error);

        $processedError = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');

        if (!headers_sent()) {
            // Every caller is an unrecoverable fault, so the response must not report
            // success. Once output has started the status is already committed.
            http_response_code(500);

            echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Poweradmin - Error</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .alert-danger { background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="alert-danger" role="alert">
        <strong>Error:</strong> ' . $processedError . '
    </div>
</body>
</html>';
        } else {
            echo '<div class="alert-danger" role="alert" style="background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
    <strong>Error:</strong> ' . $processedError . '
</div>';
        }

        if ($exit) {
            exit();
        }
    }
}
