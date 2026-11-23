<?php

namespace Kanboard\Plugin\Deed\Test;

use Kanboard\Plugin\Deed\Model\RdapParser;
use PHPUnit\Framework\TestCase;

class RdapParserTest extends TestCase
{
    public function testParsesVerisignDomain(): void
    {
        $record = $this->fixture('verisign-com');
        $record['links'][] = ['rel' => 'related', 'href' => 'https://example.com/second'];

        $this->assertSame([
            'registered_at' => 1343749427,
            'registry_expires_at' => 1817048627,
            'registrar_expires_at' => null,
            'registrar' => 'Porkbun LLC',
            'statuses' => ['client delete prohibited', 'client transfer prohibited'],
            'nameservers' => ['amanda.ns.cloudflare.com', 'devin.ns.cloudflare.com'],
            'related_url' => 'https://cart-before.porkbun.horse/rdap/domain/MADEINNEWBURGH.COM',
        ], (new RdapParser())->parseDomain($record));
    }

    public function testParsesPorkbunRegistrarExpiration(): void
    {
        $parsed = (new RdapParser())->parseDomain($this->fixture('porkbun-referral'));

        $this->assertSame(1817048627, $parsed['registrar_expires_at']);
        $this->assertSame('2027-07-31', gmdate('Y-m-d', $parsed['registrar_expires_at']));
        $this->assertNull($parsed['related_url']);
    }

    public function testSwappingExpirationActionsSwapsTheirOutputFields(): void
    {
        $record = $this->fixture('porkbun-referral');

        // The recorded expiry timestamps match. Give the registrar event a distinct date.
        $registrarEvent = array_search('registrar expiration', array_column($record['events'], 'eventAction'), true);
        $record['events'][$registrarEvent]['eventDate'] = '2027-07-30T11:43:47-04:00';

        $parser = new RdapParser();
        $original = $parser->parseDomain($record);
        $this->assertSame(1817048627, $original['registry_expires_at']);
        $this->assertSame(1816962227, $original['registrar_expires_at']);

        $registryEvent = array_search('expiration', array_column($record['events'], 'eventAction'), true);
        $record['events'][$registryEvent]['eventAction'] = 'registrar expiration';
        $record['events'][$registrarEvent]['eventAction'] = 'expiration';
        $swapped = $parser->parseDomain($record);

        $this->assertSame($original['registrar_expires_at'], $swapped['registry_expires_at']);
        $this->assertSame($original['registry_expires_at'], $swapped['registrar_expires_at']);
    }

    public function testParsesPirDomainWithoutRegistrarExpiration(): void
    {
        $parsed = (new RdapParser())->parseDomain($this->fixture('pir-org'));

        $this->assertSame(979344734, $parsed['registered_at']);
        $this->assertSame(1799799134, $parsed['registry_expires_at']);
        $this->assertNull($parsed['registrar_expires_at']);
        $this->assertSame('MarkMonitor Inc.', $parsed['registrar']);
    }

    public function testSortsCentralNicNameservers(): void
    {
        $parsed = (new RdapParser())->parseDomain($this->fixture('centralnic-xyz'));

        $this->assertSame(['ns1.google.com', 'ns2.google.com', 'ns3.google.com', 'ns4.google.com'], $parsed['nameservers']);
        $this->assertSame(1805587199, $parsed['registry_expires_at']);
    }

    public function testParsesArinNetwork(): void
    {
        $this->assertSame([
            'name' => 'GOGL',
            'handle' => 'NET-8-8-8-0-2',
            'range' => '8.8.8.0 - 8.8.8.255',
            'country' => null,
            'abuse_email' => 'network-abuse@google.com',
        ], (new RdapParser())->parseNetwork($this->fixture('ip-arin')));
    }

    public function testParsesArinAutnum(): void
    {
        $parser = new RdapParser();
        $record = $this->fixture('autnum-arin');
        $this->assertSame([
            'name' => 'GOOGLE',
            'handle' => 'AS15169',
            'range' => '15169',
            'country' => null,
        ], $parser->parseAutnum($record));

        $record['endAutnum'] = 15179;
        $record['country'] = 'US';
        $parsed = $parser->parseAutnum($record);
        $this->assertSame('15169 - 15179', $parsed['range']);
        $this->assertSame('US', $parsed['country']);
    }

    public function testMissingFieldsAreNull(): void
    {
        $parser = new RdapParser();
        $this->assertSame([
            'registered_at' => null,
            'registry_expires_at' => null,
            'registrar_expires_at' => null,
            'registrar' => null,
            'statuses' => null,
            'nameservers' => null,
            'related_url' => null,
        ], $parser->parseDomain([]));
        $this->assertSame([
            'name' => null,
            'handle' => null,
            'range' => null,
            'country' => null,
            'abuse_email' => null,
        ], $parser->parseNetwork(['startAddress' => '2001:db8::']));
        $this->assertSame([
            'name' => null,
            'handle' => null,
            'range' => null,
            'country' => null,
        ], $parser->parseAutnum([]));
    }

    public function testInvalidDatesAreNull(): void
    {
        $parsed = (new RdapParser())->parseDomain(['events' => [
            ['eventAction' => 'registration', 'eventDate' => ''],
            ['eventAction' => 'expiration', 'eventDate' => 'tomorrow'],
            ['eventAction' => 'registrar expiration', 'eventDate' => '2027-02-30T00:00:00Z'],
        ]]);

        $this->assertNull($parsed['registered_at']);
        $this->assertNull($parsed['registry_expires_at']);
        $this->assertNull($parsed['registrar_expires_at']);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
