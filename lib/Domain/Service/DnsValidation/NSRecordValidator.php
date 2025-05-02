<?php

namespace Poweradmin\Domain\Service\DnsValidation;

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * NS Record Validator
 */
class NSRecordValidator
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
     * Validate NS record
     *
     * @param string $content Nameserver hostname
     * @param string $name Domain name for the NS record
     * @param mixed $prio Priority value (should be 0 for NS records)
     * @param int|string $ttl TTL value
     * @param int $defaultTTL Default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): array|bool
    {
        // Validate content (nameserver hostname)
        $contentResult = $this->hostnameValidator->isValidHostnameFqdn($content, 0);
        if ($contentResult === false) {
            $this->messageService->addSystemError(_('Invalid nameserver hostname.'));
            return false;
        }
        $content = $contentResult['hostname'];

        // Validate name (domain name)
        $nameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($nameResult === false) {
            return false;
        }
        $name = $nameResult['hostname'];

        // Validate priority (should be 0 for NS records)
        $validatedPrio = $this->validatePriority($prio);
        if ($validatedPrio === false) {
            $this->messageService->addSystemError(_('Invalid value for prio field.'));
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
     * Validate priority for NS records
     * NS records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return int|bool 0 if valid, false otherwise
     */
    private function validatePriority(mixed $prio): int|bool
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return 0;
        }

        // If provided, ensure it's 0 for NS records
        if (is_numeric($prio) && intval($prio) === 0) {
            return 0;
        }

        return false;
    }
}
