<?php

namespace Poweradmin\Domain\Service\DnsValidation;

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * MX Record Validator
 */
class MXRecordValidator
{
    private ConfigurationManager $config;
    private MessageService $messageService;
    private TTLValidator $ttlValidator;
    private HostnameValidator $hostnameValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->ttlValidator = new TTLValidator();
        $this->hostnameValidator = new HostnameValidator($config);
    }

    /**
     * Validate MX record
     *
     * @param string $content Mail server hostname
     * @param string $name Domain name for the MX record
     * @param mixed $prio Priority value
     * @param int|string $ttl TTL value
     * @param int $defaultTTL Default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): array|bool
    {
        // Validate content (mail server hostname)
        $contentResult = $this->hostnameValidator->isValidHostnameFqdn($content, 0);
        if ($contentResult === false) {
            $this->messageService->addSystemError(_('Invalid mail server hostname.'));
            return false;
        }
        $content = $contentResult['hostname'];

        // Validate name (domain name)
        $nameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($nameResult === false) {
            return false;
        }
        $name = $nameResult['hostname'];

        // Validate priority
        $validatedPrio = $this->validatePriority($prio);
        if ($validatedPrio === false) {
            $this->messageService->addSystemError(_('Invalid value for MX priority field.'));
            return false;
        }

        // Validate TTL
        $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTtl === false) {
            return false;
        }

        return [
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ];
    }

    /**
     * Validate priority for MX records
     * MX records require a numeric priority between 0 and 65535
     *
     * @param mixed $prio Priority value
     *
     * @return int|bool The validated priority value or false if invalid
     */
    private function validatePriority(mixed $prio): int|bool
    {
        // If priority is not provided or empty, use default of 10
        if (!isset($prio) || $prio === "") {
            return 10;
        }

        // Priority must be a number between 0 and 65535
        if (is_numeric($prio) && $prio >= 0 && $prio <= 65535) {
            return (int)$prio;
        }

        return false;
    }
}
