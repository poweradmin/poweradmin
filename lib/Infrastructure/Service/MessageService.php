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

class MessageService
{
    private const TYPE_ERROR = 'error';
    private const TYPE_WARN = 'warn';
    private const TYPE_WARNING = 'warning';
    private const TYPE_SUCCESS = 'success';
    private const TYPE_INFO = 'info';

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
     * @param string $type The type of message (error, warn, success, info)
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
     * Add an error message to be displayed for a specific script
     *
     * @param string $script The script to set the message for
     * @param string $content The content of the message
     * @param string|null $recordName Optional record name for context
     */
    public function addError(string $script, string $content, ?string $recordName = null): void
    {
        $this->addMessage($script, self::TYPE_ERROR, $content, $recordName);
    }

    /**
     * Add a warning message to be displayed for a specific script
     *
     * @param string $script The script to set the message for
     * @param string $content The content of the message
     */
    public function addWarning(string $script, string $content): void
    {
        $this->addMessage($script, self::TYPE_WARN, $content);
    }

    /**
     * Add a success message to be displayed for a specific script
     *
     * @param string $script The script to set the message for
     * @param string $content The content of the message
     */
    public function addSuccess(string $script, string $content): void
    {
        $this->addMessage($script, self::TYPE_SUCCESS, $content);
    }

    /**
     * Add an info message to be displayed for a specific script
     *
     * @param string $script The script to set the message for
     * @param string $content The content of the message
     */
    public function addInfo(string $script, string $content): void
    {
        $this->addMessage($script, self::TYPE_INFO, $content);
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
     * Options for configuring system error display
     */
    private array $errorOptions = [
        'recordName' => null,
        'exit' => true,
    ];

    /**
     * Set record name context for the error message
     *
     * @param string $recordName The record name to provide context for
     * @return $this For method chaining
     */
    public function withRecordContext(string $recordName): self
    {
        $this->errorOptions['recordName'] = $recordName;
        return $this;
    }

    /**
     * Don't exit the script after displaying the error
     *
     * @return $this For method chaining
     */
    public function dontExit(): self
    {
        $this->errorOptions['exit'] = false;
        return $this;
    }

    /**
     * Reset error options to default values
     */
    private function resetErrorOptions(): void
    {
        $this->errorOptions = [
            'recordName' => null,
            'exit' => true,
        ];
    }

    /**
     * Display a system error directly with basic HTML
     * Useful for critical errors before the header/footer can be rendered
     *
     * @param string $error The error message to display
     */
    public function displayDirectSystemError(string $error): void
    {
        $recordName = $this->errorOptions['recordName'];
        $exit = $this->errorOptions['exit'];
        $this->resetErrorOptions();

        if ($recordName !== null) {
            $error = sprintf('%s (Record: %s)', $error, $recordName);
        }

        $this->addSystemError($error, $recordName);

        $processedError = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');

        if (!headers_sent()) {
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

    /**
     * Generate a unique form token
     *
     * @return string The generated token
     */
    public function generateFormToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Store form data with a specific token
     *
     * @param string $token The form token
     * @param array $data The form data to store
     */
    public function storeFormData(string $token, array $data): void
    {
        $formData = $this->userContextService->getSessionData(SessionKeys::FORM_DATA) ?? [];
        $formData[$token] = [
            'data' => $data,
            'expires' => time() + 300 // Expire after 5 minutes
        ];
        $this->userContextService->setSessionData(SessionKeys::FORM_DATA, $formData);
    }

    /**
     * Get stored form data for a token and remove it from the session
     *
     * @param string $token The form token
     * @return array|null The stored form data or null if none exists
     */
    public function getFormData(string $token): ?array
    {
        $formData = $this->userContextService->getSessionData(SessionKeys::FORM_DATA) ?? [];
        if (!isset($formData[$token])) {
            return null;
        }

        $entry = $formData[$token];
        unset($formData[$token]);
        $this->userContextService->setSessionData(SessionKeys::FORM_DATA, $formData);

        if (time() > $entry['expires']) {
            return null;
        }

        return $entry['data'];
    }

    /**
     * Clean up expired form data
     */
    public function cleanupFormData(): void
    {
        $formData = $this->userContextService->getSessionData(SessionKeys::FORM_DATA);
        if ($formData === null) {
            return;
        }

        $now = time();
        $changed = false;
        foreach ($formData as $token => $entry) {
            if ($now > $entry['expires']) {
                unset($formData[$token]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->userContextService->setSessionData(SessionKeys::FORM_DATA, $formData);
        }
    }
}
