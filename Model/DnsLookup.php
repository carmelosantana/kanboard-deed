<?php

namespace Kanboard\Plugin\Deed\Model;

use Closure;

final class DnsLookup
{
    private const TYPES = [
        'A' => DNS_A,
        'AAAA' => DNS_AAAA,
        'MX' => DNS_MX,
        'NS' => DNS_NS,
        'TXT' => DNS_TXT,
        'CNAME' => DNS_CNAME,
        'SOA' => DNS_SOA,
    ];

    private readonly Closure $resolver;

    /** @param ?Closure(string, int): (array|false) $resolver */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? dns_get_record(...);
    }

    public function lookup(string $name): array
    {
        // PHP reports resolver failures as a warning and a false return value.
        $records = @($this->resolver)($name, array_sum(self::TYPES));
        $groups = array_fill_keys(array_keys(self::TYPES), []);
        foreach ($records ?: [] as $record) {
            $type = $record['type'] ?? '';
            if (isset($groups[$type])) {
                $groups[$type][] = $record;
            }
        }

        return array_filter($groups);
    }
}
