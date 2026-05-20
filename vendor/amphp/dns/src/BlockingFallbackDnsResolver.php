<?php declare(strict_types=1);

namespace Amp\Dns;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

final class BlockingFallbackDnsResolver implements DnsResolver
{
    use ForbidCloning;
    use ForbidSerialization;

    public function resolve(string $name, ?int $typeRestriction = null, ?Cancellation $cancellation = null): array
    {
        if (!\in_array($typeRestriction, [DnsRecord::A, null], true)) {
            throw new DnsException("Query for '{$name}' failed, because loading the system's DNS configuration failed and querying records other than A records isn't supported in blocking fallback mode.");
        }

        return $this->query($name, DnsRecord::A);
    }

    public function query(string $name, int $type, ?Cancellation $cancellation = null): array
    {
        if ($type !== DnsRecord::A) {
            throw new DnsException("Query for '$name' failed, because loading the system's DNS configuration failed and querying records other than A records isn't supported in blocking fallback mode.");
        }

        $result = \gethostbynamel($name);
        if ($result === false) {
            throw new DnsException("Query for '$name' failed, because loading the system's DNS configuration failed and blocking fallback via gethostbynamel() failed, too.");
        }

        if ($result === []) {
            throw new MissingDnsRecordException("No records returned for '$name' using blocking fallback mode.");
        }

        $records = [];

        foreach ($result as $record) {
            $records[] = new DnsRecord($record, DnsRecord::A, null);
        }

        return $records;
    }
}
