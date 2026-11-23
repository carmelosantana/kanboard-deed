<?php

namespace Kanboard\Plugin\Deed\Test;

use Kanboard\Plugin\Deed\Model\DnsLookup;
use PHPUnit\Framework\TestCase;

class DnsLookupTest extends TestCase
{
    public function testGroupsSupportedRecordsAndKeepsMultipleAnswers(): void
    {
        $records = [
            ['type' => 'A', 'ip' => '192.0.2.1'],
            ['type' => 'AAAA', 'ipv6' => '2001:db8::1'],
            ['type' => 'MX', 'target' => 'mail.example.com', 'pri' => 10],
            ['type' => 'NS', 'target' => 'ns.example.com'],
            ['type' => 'TXT', 'txt' => 'v=spf1 -all'],
            ['type' => 'CNAME', 'target' => 'alias.example.com'],
            ['type' => 'SOA', 'mname' => 'ns.example.com', 'serial' => 1],
            ['type' => 'A', 'ip' => '192.0.2.2'],
            ['type' => 'CAA', 'value' => 'example.com'],
        ];
        $dns = new DnsLookup(function (string $name, int $types) use ($records): array {
            $this->assertSame('example.com', $name);
            $this->assertSame(DNS_A | DNS_AAAA | DNS_MX | DNS_NS | DNS_TXT | DNS_CNAME | DNS_SOA, $types);

            return $records;
        });

        $this->assertSame([
            'A' => [$records[0], $records[7]],
            'AAAA' => [$records[1]],
            'MX' => [$records[2]],
            'NS' => [$records[3]],
            'TXT' => [$records[4]],
            'CNAME' => [$records[5]],
            'SOA' => [$records[6]],
        ], $dns->lookup('example.com'));
    }

    public function testResolverFailureReturnsNoRecordsWithoutWarnings(): void
    {
        $dns = new DnsLookup(static function (): false {
            trigger_error('Resolver unavailable', E_USER_WARNING);
            return false;
        });

        $this->assertSame([], $dns->lookup('example.com'));
    }
}
