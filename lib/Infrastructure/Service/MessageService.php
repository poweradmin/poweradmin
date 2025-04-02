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

namespace Poweradmin\Infrastructure\Service;

class MessageService
{
    const TYPE_ERROR = 'error';
    const TYPE_WARNING = 'warn';
    const TYPE_SUCCESS = 'success';
    const TYPE_INFO = 'info';

    /**
     * Add a message to be displayed for a specific script
     *
     * @param string $script The script to set the message for
     * @param string $type The type of message (error, warn, success, info)
     * @param string $content The content of the message
     * @param string|null $recordName Optional record name for context
     */
    public function addMessage(string $script, string $type, string $content, ?string $recordName = null): void
    {
        if (!isset($_SESSION['messages'][$script])) {
            $_SESSION['messages'][$script] = [];
        }

        if ($recordName !== null) {
            $content = sprintf('%s (Record: %s)', $content, $recordName);
        }

        $_SESSION['messages'][$script][] = [
            'type' => $type,
            'content' => $content
        ];
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
        $this->addMessage($script, self::TYPE_WARNING, $content);
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
        if (isset($_SESSION['messages'][$script])) {
            $messages = $_SESSION['messages'][$script];
            unset($_SESSION['messages'][$script]);
            return $messages;
        }
        return null;
    }

    /**
     * Display messages for a specific template
     *
     * @param string $template The template to display messages for
     * @return string HTML output with messages
     */
    public function renderMessages(string $template): string
    {
        $script = pathinfo($template)['filename'];
        $output = '';

        $messages = $this->getMessages($script);
        if ($messages) {
            foreach ($messages as $message) {
                $alertClass = match ($message['type']) {
                    self::TYPE_ERROR => 'alert-danger',
                    self::TYPE_WARNING => 'alert-warning',
                    self::TYPE_SUCCESS => 'alert-success',
                    self::TYPE_INFO => 'alert-info',
                    default => '',
                };

                $output .= <<<EOF
<div class="alert $alertClass alert-dismissible fade show" role="alert" data-testid="alert-message">{$message['content']}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
EOF;
            }
        }

        return $output;
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
     * Static method to add a system error message for static contexts
     *
     * @param string $error The error message to display
     * @param string|null $recordName Optional record name for context
     */
    public static function addStaticSystemError(string $error, ?string $recordName = null): void
    {
        $messageService = new self();
        $messageService->addSystemError($error, $recordName);
    }
}
