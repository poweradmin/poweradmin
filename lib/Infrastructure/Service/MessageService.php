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
    private const TYPE_ERROR = 'error';
    private const TYPE_WARN = 'warn';
    private const TYPE_WARNING = 'warning';
    private const TYPE_SUCCESS = 'success';
    private const TYPE_INFO = 'info';

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
        if (!isset($_SESSION['messages'][$script])) {
            $_SESSION['messages'][$script] = [];
        }

        if ($recordName !== null) {
            $content = sprintf('%s (Record: %s)', $content, $recordName);
        }

        $newMessage = [
            'type' => $type,
            'content' => $content
        ];

        // Check if this message already exists to prevent duplicates
        $isDuplicate = false;
        foreach ($_SESSION['messages'][$script] as $existingMessage) {
            if ($existingMessage['type'] === $type && $existingMessage['content'] === $content) {
                $isDuplicate = true;
                break;
            }
        }

        // Only add the message if it's not a duplicate
        if (!$isDuplicate) {
            $_SESSION['messages'][$script][] = $newMessage;
        }
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
                    self::TYPE_WARN, self::TYPE_WARNING => 'alert-warning',
                    self::TYPE_SUCCESS => 'alert-success',
                    self::TYPE_INFO => 'alert-info',
                    default => '',
                };

                $bgClass = str_replace('alert-', '', $alertClass);
                $borderClass = str_replace('alert-', 'border-', $alertClass);
                $textClass = str_replace('alert-', 'text-', $alertClass);

                $icon = match ($message['type']) {
                    self::TYPE_ERROR, self::TYPE_WARNING => 'exclamation-triangle',
                    self::TYPE_WARN => 'exclamation-circle',
                    self::TYPE_SUCCESS => 'check-circle',
                    default => 'info-circle',
                };

                $title = match ($message['type']) {
                    self::TYPE_ERROR => 'Error:',
                    self::TYPE_WARN, self::TYPE_WARNING => 'Warning:',
                    self::TYPE_SUCCESS => 'Success:',
                    self::TYPE_INFO => 'Info:',
                    default => '',
                };

                $output .= <<<EOF
<div class="alert $alertClass bg-$bgClass bg-opacity-10 py-2 border $borderClass alert-dismissible small fade show" role="alert" data-testid="alert-message">
    <i class="bi bi-$icon-fill me-2 $textClass"></i>
    <strong class="$textClass">$title</strong> {$message['content']}
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
     * Options for configuring system error display
     */
    private array $errorOptions = [
        'recordName' => null,
        'exit' => true,
        'allowHtml' => false
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
     * Allow HTML in the error message
     *
     * @return $this For method chaining
     */
    public function allowHtml(): self
    {
        $this->errorOptions['allowHtml'] = true;
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
            'allowHtml' => false
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
        // Extract options and reset for next call
        $recordName = $this->errorOptions['recordName'];
        $exit = $this->errorOptions['exit'];
        $allowHtml = $this->errorOptions['allowHtml'];
        $this->resetErrorOptions();

        if ($recordName !== null) {
            $error = sprintf('%s (Record: %s)', $error, $recordName);
        }

        // First store the error in the session for later display if needed
        $this->addSystemError($error, $recordName);

        // Process the error message based on allowHtml parameter
        $processedError = $allowHtml ? $error : htmlspecialchars($error, ENT_QUOTES);

        // Check if headers have been sent - if not, we can output a complete HTML page
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
        a { color: #007bff; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="alert-danger" role="alert">
        <strong>Error:</strong> ' . $processedError . '
    </div>
</body>
</html>';
        } else {
            // Headers already sent, output just the message
            echo '<div class="alert-danger" role="alert" style="background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
    <strong>Error:</strong> ' . $processedError . '
</div>';
        }

        if ($exit) {
            exit();
        }
    }

    /**
     * Display a system error with HTML content
     * Convenience method that automatically sets allowHtml to true
     *
     * @param string $error The HTML error message to display
     */
    public function displayHtmlError(string $error): void
    {
        $this->allowHtml()->displayDirectSystemError($error);
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
        if (!isset($_SESSION['form_data'])) {
            $_SESSION['form_data'] = [];
        }
        $_SESSION['form_data'][$token] = [
            'data' => $data,
            'expires' => time() + 300 // Expire after 5 minutes
        ];
    }

    /**
     * Get stored form data for a token and remove it from the session
     *
     * @param string $token The form token
     * @return array|null The stored form data or null if none exists
     */
    public function getFormData(string $token): ?array
    {
        if (isset($_SESSION['form_data'][$token])) {
            $formData = $_SESSION['form_data'][$token];

            // Check if the data has expired
            if (time() > $formData['expires']) {
                unset($_SESSION['form_data'][$token]);
                return null;
            }

            $data = $formData['data'];
            unset($_SESSION['form_data'][$token]);
            return $data;
        }
        return null;
    }

    /**
     * Clean up expired form data
     */
    public function cleanupFormData(): void
    {
        if (!isset($_SESSION['form_data'])) {
            return;
        }

        $now = time();
        foreach ($_SESSION['form_data'] as $token => $formData) {
            if ($now > $formData['expires']) {
                unset($_SESSION['form_data'][$token]);
            }
        }
    }
}
