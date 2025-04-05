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

namespace Poweradmin\Application\Service;

use Exception;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Logger\LegacyLoggerInterface;
use Psr\Log\LoggerInterface;

class MailService
{
    private ConfigurationManager $config;
    private ?LoggerInterface $logger;

    public function __construct(ConfigurationManager $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Send an email using the configured mail transport
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param string $plainBody Email body (plain text, optional)
     * @param array $headers Additional headers (optional)
     * @return bool True if email was sent successfully, false otherwise
     */
    public function sendMail(
        string $to,
        string $subject,
        string $body,
        string $plainBody = '',
        array $headers = []
    ): bool {
        // Check if mail functionality is enabled
        if (!$this->config->get('mail', 'enabled', false)) {
            $this->logWarning('Mail sending failed: mail functionality is disabled in configuration');
            return false;
        }

        // Determine which transport to use
        $transportType = $this->config->get('mail', 'transport', 'smtp');

        try {
            switch ($transportType) {
                case 'smtp':
                    return $this->sendSmtp($to, $subject, $body, $plainBody, $headers);
                case 'sendmail':
                    return $this->sendSendmail($to, $subject, $body, $plainBody, $headers);
                case 'php':
                default:
                    return $this->sendPhpMail($to, $subject, $body, $plainBody, $headers);
            }
        } catch (Exception $e) {
            $this->logError('Mail sending failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a new account email with login credentials
     *
     * @param string $to Recipient email address
     * @param string $username User's username
     * @param string $password User's password
     * @param string $fullname User's full name (optional)
     * @return bool True if email was sent successfully, false otherwise
     */
    public function sendNewAccountEmail(
        string $to,
        string $username,
        string $password,
        string $fullname = ''
    ): bool {
        $subject = $this->config->get('mail', 'password_email_subject', 'Your new account information');

        // Create both HTML and plain text versions of the email
        $htmlBody = $this->getNewAccountEmailHtml($username, $password, $fullname);
        $plainBody = $this->getNewAccountEmailPlain($username, $password, $fullname);

        return $this->sendMail($to, $subject, $htmlBody, $plainBody);
    }

    /**
     * Create HTML version of new account email
     */
    private function getNewAccountEmailHtml(string $username, string $password, string $fullname): string
    {
        $greeting = empty($fullname) ? 'Hello' : "Hello $fullname";
        $emailTitle = $this->config->get('mail', 'email_title', 'Your DNS Account Information');
        $emailSignature = $this->config->get('mail', 'email_signature', 'DNS Admin');

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Your Account Information</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #2c3e50;">' . htmlspecialchars($emailTitle) . '</h2>
    <p>' . $greeting . ',</p>
    <p>Your account has been created. Here are your login details:</p>
    <table style="border-collapse: collapse; width: 100%; margin: 20px 0; border: 1px solid #ddd;">
        <tr>
            <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Username:</strong></td>
            <td style="padding: 10px; border: 1px solid #ddd;">' . htmlspecialchars($username) . '</td>
        </tr>
        <tr>
            <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Password:</strong></td>
            <td style="padding: 10px; border: 1px solid #ddd;">' . htmlspecialchars($password) . '</td>
        </tr>
    </table>
    <p>For security reasons, please change your password after your first login.</p>
    <p>If you have any questions, please contact your administrator.</p>
    <p>Thank you,<br>' . htmlspecialchars($emailSignature) . '</p>
</body>
</html>';
    }

    /**
     * Create plain text version of new account email
     */
    private function getNewAccountEmailPlain(string $username, string $password, string $fullname): string
    {
        $greeting = empty($fullname) ? 'Hello' : "Hello $fullname";
        $emailTitle = $this->config->get('mail', 'email_title', 'Your DNS Account Information');
        $emailSignature = $this->config->get('mail', 'email_signature', 'DNS Admin');

        return $greeting . ",\n\n" .
            "Your account has been created. Here are your login details:\n\n" .
            "Username: " . $username . "\n" .
            "Password: " . $password . "\n\n" .
            "For security reasons, please change your password after your first login.\n\n" .
            "If you have any questions, please contact your administrator.\n\n" .
            "Thank you,\n" .
            $emailSignature;
    }

    /**
     * Send mail via PHP mail() function
     */
    private function sendPhpMail(
        string $to,
        string $subject,
        string $body,
        string $plainBody,
        array $headers
    ): bool {
        $fromEmail = $this->config->get('mail', 'from', 'poweradmin@example.com');
        $fromName = $this->config->get('mail', 'from_name', '');

        // Set up email headers
        $mailHeaders = $this->getBaseHeaders($fromEmail, $fromName, $plainBody !== '');
        $mailHeaders = array_merge($mailHeaders, $headers);

        // Convert headers array to string
        $headersStr = '';
        foreach ($mailHeaders as $name => $value) {
            $headersStr .= "$name: $value\r\n";
        }

        // Create message body (multipart if we have plain text version)
        $messageBody = $this->getMessageBody($body, $plainBody);

        // Send the email
        return mail($to, $subject, $messageBody, $headersStr);
    }

    /**
     * Send mail via Sendmail
     */
    private function sendSendmail(
        string $to,
        string $subject,
        string $body,
        string $plainBody,
        array $headers
    ): bool {
        $fromEmail = $this->config->get('mail', 'from', 'poweradmin@example.com');
        $fromName = $this->config->get('mail', 'from_name', '');
        $sendmailPath = $this->config->get('mail', 'sendmail_path', '/usr/sbin/sendmail -bs');

        // Set up email headers
        $mailHeaders = $this->getBaseHeaders($fromEmail, $fromName, $plainBody !== '');
        $mailHeaders = array_merge($mailHeaders, $headers);

        try {
            // Open sendmail process
            $sendmail = popen($sendmailPath, 'w');
            if (!$sendmail) {
                throw new Exception("Failed to open sendmail process: $sendmailPath");
            }

            // Write headers
            fputs($sendmail, "To: $to\r\n");
            fputs($sendmail, "Subject: $subject\r\n");
            foreach ($mailHeaders as $name => $value) {
                fputs($sendmail, "$name: $value\r\n");
            }
            fputs($sendmail, "\r\n");

            // Write message body
            fputs($sendmail, $this->getMessageBody($body, $plainBody));

            // Close sendmail process
            $status = pclose($sendmail);

            return $status === 0;
        } catch (Exception $e) {
            $this->logError('Sendmail error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send mail via SMTP
     *
     * Note: This is a simplified implementation. In a production environment,
     * you might want to use a library like PHPMailer or Symfony Mailer instead.
     */
    private function sendSmtp(
        string $to,
        string $subject,
        string $body,
        string $plainBody,
        array $headers
    ): bool {
        $host = $this->config->get('mail', 'host', 'localhost');
        $port = $this->config->get('mail', 'port', 25);
        $encryption = $this->config->get('mail', 'encryption', '');
        $fromEmail = $this->config->get('mail', 'from', 'poweradmin@example.com');
        $fromName = $this->config->get('mail', 'from_name', '');

        // Set prefix for encrypted connections
        $prefix = '';
        if ($encryption === 'ssl') {
            $prefix = 'ssl://';
        } elseif ($encryption === 'tls') {
            $prefix = 'tls://';
        }

        try {
            // Connect to SMTP server
            $socket = fsockopen($prefix . $host, $port, $errno, $errstr, 30);
            if (!$socket) {
                throw new Exception("SMTP connection failed: $errstr ($errno)");
            }

            // Set timeout for socket operations
            stream_set_timeout($socket, 30);

            // Read server greeting
            $this->readSmtpResponse($socket);

            // Say hello
            $this->sendSmtpCommand($socket, "EHLO " . gethostname());

            // Start TLS if needed and not already using SSL
            if ($encryption === 'tls' && $prefix !== 'ssl://') {
                $this->sendSmtpCommand($socket, "STARTTLS");
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->sendSmtpCommand($socket, "EHLO " . gethostname());
            }

            // Authenticate if required
            if ($this->config->get('mail', 'auth', false)) {
                $username = $this->config->get('mail', 'username', '');
                $password = $this->config->get('mail', 'password', '');

                $this->sendSmtpCommand($socket, "AUTH LOGIN");
                $this->sendSmtpCommand($socket, base64_encode($username));
                $this->sendSmtpCommand($socket, base64_encode($password));
            }

            // Set sender
            $this->sendSmtpCommand($socket, "MAIL FROM:<$fromEmail>");

            // Set recipient
            $this->sendSmtpCommand($socket, "RCPT TO:<$to>");

            // Start data
            $this->sendSmtpCommand($socket, "DATA");

            // Set up email headers
            $mailHeaders = $this->getBaseHeaders($fromEmail, $fromName, $plainBody !== '');
            $mailHeaders = array_merge($mailHeaders, $headers);

            // Send headers
            fputs($socket, "To: $to\r\n");
            fputs($socket, "Subject: $subject\r\n");
            foreach ($mailHeaders as $name => $value) {
                fputs($socket, "$name: $value\r\n");
            }
            fputs($socket, "\r\n");

            // Send message body
            fputs($socket, $this->getMessageBody($body, $plainBody));

            // End data
            $this->sendSmtpCommand($socket, "\r\n.");

            // Quit
            fputs($socket, "QUIT\r\n");

            // Close connection
            fclose($socket);

            return true;
        } catch (Exception $e) {
            $this->logError('SMTP error: ' . $e->getMessage());
            if (isset($socket) && is_resource($socket)) {
                fclose($socket);
            }
            return false;
        }
    }

    /**
     * Send an SMTP command and check the response
     */
    private function sendSmtpCommand($socket, string $command): void
    {
        fputs($socket, $command . "\r\n");
        $response = $this->readSmtpResponse($socket);

        // Check if the response code indicates an error
        $responseCode = substr($response, 0, 3);
        if ($responseCode[0] === '4' || $responseCode[0] === '5') {
            throw new Exception("SMTP error: $response");
        }
    }

    /**
     * Read an SMTP response
     */
    private function readSmtpResponse($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            // If the 4th character is a space, this is the last line of the response
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    }

    /**
     * Get base headers for email
     */
    private function getBaseHeaders(string $fromEmail, string $fromName, bool $isMultipart): array
    {
        $headers = [
            'From' => empty($fromName) ? $fromEmail : "$fromName <$fromEmail>",
            'X-Mailer' => 'PowerAdmin Mailer',
            'MIME-Version' => '1.0',
        ];

        if ($isMultipart) {
            $boundary = md5(uniqid(time()));
            $headers['Content-Type'] = "multipart/alternative; boundary=\"$boundary\"";
        } else {
            $headers['Content-Type'] = 'text/html; charset=UTF-8';
        }

        return $headers;
    }

    /**
     * Construct message body (multipart if plain text is provided)
     */
    private function getMessageBody(string $htmlBody, string $plainBody): string
    {
        // If no plain text body is provided, just return the HTML body
        if (empty($plainBody)) {
            return $htmlBody;
        }

        // Otherwise, create a multipart message
        $boundary = md5(uniqid(time()));

        $message = "--$boundary\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $plainBody . "\r\n\r\n";

        $message .= "--$boundary\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $htmlBody . "\r\n\r\n";

        $message .= "--$boundary--";

        return $message;
    }

    /**
     * Log an error message
     */
    private function logError(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->error($message);
        }
    }

    /**
     * Log a warning message
     */
    private function logWarning(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);
        }
    }
}
