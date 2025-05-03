<?php

namespace Poweradmin\Domain\Utility;

/**
 * Wrapper for network functions to allow for easier testing
 */
class NetworkUtility
{
    /**
     * Instance for testing mock purposes
     *
     * @var NetworkUtility|null
     */
    private static $instance = null;

    /**
     * Wrapper for inet_pton function
     *
     * @param string $ip IP address
     * @return string|false Binary representation of the IP address or false on failure
     */
    public static function inetPton(string $ip)
    {
        // If we have a test instance, use it
        if (self::$instance !== null) {
            return self::$instance->inetPton($ip);
        }

        // Default to real function
        if (!function_exists('inet_pton')) {
            // Fallback implementation if inet_pton is not available
            // This would be a simplified version, not for production
            return false;
        }

        return inet_pton($ip);
    }

    /**
     * Wrapper for inet_ntop function
     *
     * @param string $binary Binary representation of the IP address
     * @return string|false IP address or false on failure
     */
    public static function inetNtop(string $binary)
    {
        // If we have a test instance, use it
        if (self::$instance !== null) {
            return self::$instance->inetNtop($binary);
        }

        // Default to real function
        if (!function_exists('inet_ntop')) {
            // Fallback implementation if inet_ntop is not available
            return false;
        }

        return inet_ntop($binary);
    }
}
