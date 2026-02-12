<?php declare(strict_types=1);

namespace Amp\Dns;

use Amp\Cancellation;
use Revolt\EventLoop;

/**
 * Retrieve the application-wide DNS resolver instance.
 *
 * @param DnsResolver|null $dnsResolver Optionally specify a new default DNS resolver instance
 *
 * @return DnsResolver Returns the application-wide DNS resolver instance
 */
function dnsResolver(?DnsResolver $dnsResolver = null): DnsResolver
{
    static $map;
    $map ??= new \WeakMap();
    $driver = EventLoop::getDriver();

    if ($dnsResolver) {
        return $map[$driver] = $dnsResolver;
    }

    return $map[$driver] ??= createDefaultResolver();
}

/**
 * Create a new DNS resolver best-suited for the current environment.
 */
function createDefaultResolver(): DnsResolver
{
    return new Rfc1035StubDnsResolver;
}

/**
 * @throws DnsException
 *@see DnsResolver::resolve()
 *
 */
function resolve(string $name, ?int $typeRestriction = null, ?Cancellation $cancellation = null): array
{
    return dnsResolver()->resolve($name, $typeRestriction, $cancellation);
}

/**
 * @throws DnsException
 *@see DnsResolver::query()
 *
 */
function query(string $name, int $type, ?Cancellation $cancellation = null): array
{
    return dnsResolver()->query($name, $type, $cancellation);
}

/**
 * Checks whether a string is a valid DNS name.
 *
 * @param string $name String to check.
 */
function isValidName(string $name): bool
{
    try {
        normalizeName($name);
        return true;
    } catch (InvalidNameException) {
        return false;
    }
}

/**
 * Normalizes a DNS name and automatically checks it for validity.
 *
 * @param string $name DNS name.
 *
 * @return string Normalized DNS name.
 * @throws InvalidNameException If an invalid name or an IDN name without ext/intl being installed has been passed.
 */
function normalizeName(string $name): string
{
    static $pattern = '/^(?<name>[a-z0-9]([a-z0-9-_]{0,61}[a-z0-9])?)(\.(?&name))*\.?$/i';

    if (\function_exists('idn_to_ascii') && \defined('INTL_IDNA_VARIANT_UTS46')) {
        if (false === $result = \idn_to_ascii($name, 0, \INTL_IDNA_VARIANT_UTS46)) {
            throw new InvalidNameException("Name '{$name}' could not be processed for IDN.");
        }

        $name = $result;
    } elseif (\preg_match('/[\x80-\xff]/', $name)) {
        throw new InvalidNameException(
            "Name '{$name}' contains non-ASCII characters and IDN support is not available. " .
            "Verify that ext/intl is installed for IDN support and that ICU is at least version 4.6."
        );
    }

    if (isset($name[253]) || !\preg_match($pattern, $name)) {
        throw new InvalidNameException("Name '{$name}' is not a valid hostname.");
    }

    if ($name[-1] === '.') {
        $name = \substr($name, 0, -1);
    }

    return $name;
}
