<?php

namespace Kanboard\Plugin\Deed\Client;

class Resolver
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        $ipv4 = @gethostbynamel($host) ?: [];
        $ipv6 = @dns_get_record($host, DNS_AAAA);
        if ($ipv6 === false) {
            return [];
        }

        return array_merge($ipv4, array_column($ipv6, 'ipv6'));
    }
}
