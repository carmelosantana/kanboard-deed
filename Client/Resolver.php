<?php

namespace Kanboard\Plugin\Deed\Client;

class Resolver
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        $ipv4 = $this->lookupA($host) ?: [];
        $ipv6 = $this->lookupAaaa($host) ?: [];

        return array_merge($ipv4, array_column($ipv6, 'ipv6'));
    }

    protected function lookupA(string $host): array|false
    {
        return @gethostbynamel($host);
    }

    protected function lookupAaaa(string $host): array|false
    {
        return @dns_get_record($host, DNS_AAAA);
    }
}
