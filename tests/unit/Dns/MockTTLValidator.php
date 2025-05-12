<?php

namespace unit\Dns;

use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * A mock TTL validator for testing warning functionality
 *
 * This class extends the real TTLValidator but overrides the validate method
 * to explicitly add warnings to the result data.
 */
class MockTTLValidator extends TTLValidator
{
    /**
     * Override the validate method to explicitly add warnings for testing
     */
    public function validate(mixed $ttl, mixed $defaultTtl, bool $checkRecommended = false, string $recordType = ''): ValidationResult
    {
        // Run parent validation
        $result = parent::validate($ttl, $defaultTtl, $checkRecommended, $recordType);

        if ($result->isValid() && $checkRecommended) {
            $data = $result->getData();

            // Explicitly add warnings based on ttl value
            if (is_numeric($ttl)) {
                $ttlValue = (int)$ttl;

                if ($ttlValue < 300) {
                    $data['warnings'] = ['TTL value is below the recommended minimum of 300 seconds'];
                } elseif ($ttlValue > 604800) {
                    $data['warnings'] = ['TTL value is above the recommended maximum of 604800 seconds'];
                }

                if ($recordType === 'SOA' && $ttlValue < 3600) {
                    $data['warnings'] = ['SOA record TTL is below the recommended minimum of 3600 seconds'];
                }

                // Return a new success result with the warnings
                return ValidationResult::success($data);
            }
        }

        return $result;
    }

    /**
     * Implementation of validateForRecordType that adds warnings
     */
    public function validateForRecordType(mixed $ttl, mixed $defaultTtl, string $recordType): ValidationResult
    {
        return $this->validate($ttl, $defaultTtl, true, $recordType);
    }
}
