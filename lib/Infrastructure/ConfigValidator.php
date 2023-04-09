<?php

namespace Poweradmin\Infrastructure;

class ConfigValidator
{
    private array $config;
    private array $errors;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->errors = [];
    }

    public function validate(): bool
    {
        $this->errors = [];

        $this->validateSyslogUse();
        if ($this->config['syslog_use']) {
            $this->validateSyslogIdent();
            $this->validateSyslogFacility();
        }

        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    private function validateSyslogUse(): void
    {
        if (!is_bool($this->config['syslog_use'])) {
            $this->errors['syslog_use'] = 'syslog_use must be a boolean value (unquoted true or false)';
        }
    }

    private function validateSyslogIdent(): void
    {
        if (!is_string($this->config['syslog_ident']) || empty($this->config['syslog_ident'])) {
            $this->errors['syslog_ident'] = 'syslog_ident must be a non-empty string';
        }
    }

    private function validateSyslogFacility(): void
    {
        $validFacilities = [
            'LOG_USER' => LOG_USER,
            'LOG_LOCAL0' => LOG_LOCAL0,
            'LOG_LOCAL1' => LOG_LOCAL1,
            'LOG_LOCAL2' => LOG_LOCAL2,
            'LOG_LOCAL3' => LOG_LOCAL3,
            'LOG_LOCAL4' => LOG_LOCAL4,
            'LOG_LOCAL5' => LOG_LOCAL5,
            'LOG_LOCAL6' => LOG_LOCAL6,
            'LOG_LOCAL7' => LOG_LOCAL7,
        ];

        if (!in_array($this->config['syslog_facility'], $validFacilities, true)) {
            $validFacilitiesList = implode(', ', array_keys($validFacilities));
            $this->errors['syslog_facility'] = "syslog_facility must be an unquoted value and one of the following values: {$validFacilitiesList}";
        }
    }
}
