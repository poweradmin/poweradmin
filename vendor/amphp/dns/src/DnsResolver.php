<?php declare(strict_types=1);

namespace Amp\Dns;

use Amp\Cancellation;

interface DnsResolver
{
    /**
     * Resolves a hostname name to an IP address [hostname as defined by RFC 3986].
     *
     * Upon success this method returns an array of Record objects. If the domain cannot be resolved,
     * a {@see DnsException} is thrown.
     *
     * A null $ttl value indicates the DNS name was resolved from the cache or the local hosts file.
     *
     * @param string $name The hostname to resolve.
     * @param int|null $typeRestriction Optional type restriction to `Record::A` or `Record::AAAA`, otherwise `null`.
     *
     * @return non-empty-list<DnsRecord>
     *
     * @throws MissingDnsRecordException
     * @throws DnsException
     */
    public function resolve(string $name, ?int $typeRestriction = null, ?Cancellation $cancellation = null): array;

    /**
     * Query specific DNS records.
     *
     * Upon success this method returns an array of Record objects. If no records of the given type are found,
     * a {@see DnsException} is thrown.
     *
     * @param string $name Record to question, A, AAAA and PTR queries are automatically normalized.
     * @param int $type Use constants of Amp\Dns\Record.
     *
     * @return non-empty-list<DnsRecord>
     *
     * @throws DnsException
     */
    public function query(string $name, int $type, ?Cancellation $cancellation = null): array;
}
