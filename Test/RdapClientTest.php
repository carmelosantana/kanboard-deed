<?php

namespace Kanboard\Plugin\Deed\Test;

use Kanboard\Plugin\Deed\Client\Bootstrap;
use Kanboard\Plugin\Deed\Client\HttpFetcher;
use Kanboard\Plugin\Deed\Client\HttpResponse;
use Kanboard\Plugin\Deed\Client\RdapClient;
use Kanboard\Plugin\Deed\Model\CacheModel;
use Kanboard\Plugin\Deed\Model\RdapParser;
use Kanboard\Plugin\Deed\Plugin;
use KanboardTests\units\Base;
use RuntimeException;
use function Kanboard\Plugin\Deed\Schema\version_1;

require_once 'tests/units/Base.php';

class RdapClientTest extends Base
{
    private const DOMAIN = 'madeinnewburgh.com';
    private const REGISTRY = 'https://rdap.verisign.com/com/v1/domain/';
    private const REFERRAL = 'https://cart-before.porkbun.horse/rdap/domain/MADEINNEWBURGH.COM';
    private const IANA = 'https://data.iana.org/rdap/';
    private const ARIN = 'https://rdap.arin.net/registry/';

    private CacheModel $cache;
    private int $now = 1795219200;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        $pdo = $this->container['db']->getConnection();
        version_1($pdo);
        $this->cache = new CacheModel($pdo);
    }

    public function testPluginRegistersLookupServices(): void
    {
        (new Plugin($this->container))->initialize();

        $this->assertInstanceOf(HttpFetcher::class, $this->container['deedHttp']);
        $this->assertInstanceOf(Bootstrap::class, $this->container['deedBootstrap']);
        $this->assertInstanceOf(RdapClient::class, $this->container['deedRdap']);
        $this->assertInstanceOf(RdapParser::class, $this->container['deedParser']);
        $this->assertInstanceOf(CacheModel::class, $this->container['deedCache']);
    }

    public function testMergesOnlyOneReferralAndPrefersRegistryFields(): void
    {
        $registry = json_decode($this->fixture('verisign-com.json'), true, flags: JSON_THROW_ON_ERROR);
        unset($registry['entities']);
        $registry['events'][] = ['eventAction' => 'registrar expiration', 'eventDate' => '2027-08-02T00:00:00Z'];
        $registry['status'] = [];

        $referral = json_decode($this->fixture('porkbun-referral.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($referral['events'] as &$event) {
            if ($event['eventAction'] === 'expiration') {
                $event['eventDate'] = '2027-08-01T00:00:00Z';
            } elseif ($event['eventAction'] === 'registrar expiration') {
                $event['eventDate'] = '2027-07-30T11:43:47-04:00';
            }
        }
        unset($event);
        $referral['status'] = ['active'];
        $referral['nameservers'] = [['ldhName' => 'ns.example.net']];
        $referral['links'][] = ['rel' => 'related', 'href' => 'https://rdap.example.net/another-referral'];

        $client = $this->clientExpecting([
            [self::IANA.'dns.json', $this->response('iana-dns.json')],
            [self::REGISTRY.self::DOMAIN, new HttpResponse(200, json_encode($registry, JSON_THROW_ON_ERROR))],
            [self::REFERRAL, new HttpResponse(200, json_encode($referral, JSON_THROW_ON_ERROR))],
        ]);

        $result = $client->lookupDomain(' MadeInNewburgh.COM. ');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(self::REGISTRY.self::DOMAIN, $result['rdap_url']);
        $this->assertSame(self::REFERRAL, $result['related_url']);
        $this->assertSame(1817048627, $result['registry_expires_at']);
        $this->assertSame(1816962227, $result['registrar_expires_at']);
        $this->assertSame('Porkbun LLC', $result['registrar']);
        $this->assertSame([], $result['statuses']);
        $this->assertSame(['amanda.ns.cloudflare.com', 'devin.ns.cloudflare.com'], $result['nameservers']);
        $this->assertNull($result['referral_error']);
        $this->assertSame($result, $this->clientExpecting([])->lookupDomain(self::DOMAIN));
    }

    /** @dataProvider referralFailures */
    public function testReferralFailureKeepsTheRegistryData(HttpResponse|RuntimeException $failure, string $error): void
    {
        $client = $this->clientExpecting([
            [self::IANA.'dns.json', $this->response('iana-dns.json')],
            [self::REGISTRY.self::DOMAIN, $this->response('verisign-com.json')],
            [self::REFERRAL, $failure],
        ]);

        $result = $client->lookupDomain(self::DOMAIN);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1817048627, $result['registry_expires_at']);
        $this->assertNull($result['registrar_expires_at']);
        $this->assertSame('Porkbun LLC', $result['registrar']);
        $this->assertSame($error, $result['referral_error']);
    }

    public static function referralFailures(): iterable
    {
        yield 'HTTP failure' => [new HttpResponse(404, ''), 'http_404'];
        yield 'fetcher rejection' => [new RuntimeException('blocked_address'), 'blocked_address'];
        yield 'invalid RDAP document' => [new HttpResponse(200, '{"errorCode": 404}'), 'invalid_rdap_response'];
    }

    /** @dataProvider cacheLifetimes */
    public function testLookupCachePersistsUntilItsTtl(string $fixture, int $httpCode, string $status, int $ttl): void
    {
        $domain = $httpCode === 200 ? self::DOMAIN : 'deed-fixture-nonexistent-7273.com';
        $lookup = [[self::REGISTRY.$domain, $this->response($fixture, $httpCode)]];
        if ($httpCode === 200) {
            $lookup[] = [self::REFERRAL, $this->response('porkbun-referral.json')];
        }
        $client = $this->clientExpecting(array_merge([
            [self::IANA.'dns.json', new HttpResponse(200, $this->fixture('iana-dns.json'), ['Cache-Control' => 'max-age=172800'])],
        ], $lookup));
        $result = $client->lookupDomain($domain);
        $this->assertSame($status, $result['status']);

        $this->now += $ttl - 1;
        $this->assertSame($result, $this->clientExpecting([])->lookupDomain($domain));

        ++$this->now;
        $this->assertSame($status, $this->clientExpecting($lookup)->lookupDomain($domain)['status']);
    }

    public static function cacheLifetimes(): iterable
    {
        yield 'success for 24 hours' => ['verisign-com.json', 200, 'ok', 86400];
        yield 'empty 404 for one hour' => ['not-found.txt', 404, 'not_found', 3600];
    }

    public function testUnknownTldDoesNotFetchARegistry(): void
    {
        $client = $this->clientExpecting([[self::IANA.'dns.json', $this->response('iana-dns.json')]]);

        $this->assertSame('unsupported', $client->lookupDomain('example.unknown-tld')['status']);
    }

    public function testRegistryRateLimitIsReturnedAndDoesNotEnterTheLookupCache(): void
    {
        $url = self::REGISTRY.self::DOMAIN;
        $client = $this->clientExpecting([
            [self::IANA.'dns.json', $this->response('iana-dns.json')],
            [$url, new HttpResponse(429, '', ['Retry-After' => '7200'])],
            [$url, $this->response('not-found.txt', 404)],
        ]);
        $result = $client->lookupDomain(self::DOMAIN);
        $this->assertSame('error', $result['status']);
        $this->assertSame('http_429', $result['error']);
        $this->assertSame(7200, $result['retry_after']);
        $this->assertSame('not_found', $client->lookupDomain(self::DOMAIN)['status']);
    }

    public function testRefreshCanBypassTheLookupCache(): void
    {
        $url = self::REGISTRY.self::DOMAIN;
        $client = $this->clientExpecting([
            [self::IANA.'dns.json', $this->response('iana-dns.json')],
            [$url, $this->response('not-found.txt', 404)],
            [$url, $this->response('verisign-com.json')],
            [self::REFERRAL, $this->response('porkbun-referral.json')],
        ]);

        $this->assertSame('not_found', $client->lookupDomain(self::DOMAIN)['status']);
        $refreshed = $client->lookupDomain(self::DOMAIN, useCache: false);
        $this->assertSame('ok', $refreshed['status']);
        $this->assertSame($refreshed, $this->clientExpecting([])->lookupDomain(self::DOMAIN));
    }

    public function testUsesEarlierExpiryAndAllowsEitherFieldToBeMissing(): void
    {
        $this->assertSame(10, RdapClient::effectiveExpiry(10, 20));
        $this->assertSame(10, RdapClient::effectiveExpiry(20, 10));
        $this->assertSame(10, RdapClient::effectiveExpiry(10, null));
        $this->assertSame(10, RdapClient::effectiveExpiry(null, 10));
        $this->assertNull(RdapClient::effectiveExpiry(null, null));
    }

    public function testQueriesArinForIpAndAsnAndCachesBothResults(): void
    {
        $client = $this->clientExpecting([
            [self::IANA.'ipv4.json', $this->response('iana-ipv4.json')],
            [self::ARIN.'ip/8.8.8.8', $this->response('ip-arin.json')],
            [self::IANA.'asn.json', $this->response('iana-asn.json')],
            [self::ARIN.'autnum/15169', $this->response('autnum-arin.json')],
        ]);

        $ip = $client->lookupIp('8.8.8.8');
        $this->assertSame('ok', $ip['status']);
        $this->assertSame('8.8.8.0 - 8.8.8.255', $ip['range']);
        $this->assertSame('network-abuse@google.com', $ip['abuse_email']);

        $asn = $client->lookupAsn('as15169');
        $this->assertSame('ok', $asn['status']);
        $this->assertSame('AS15169', $asn['handle']);
        $this->assertSame('15169', $asn['range']);

        $cached = $this->clientExpecting([]);
        $this->assertSame($ip, $cached->lookupIp('8.8.8.8'));
        $this->assertSame($asn, $cached->lookupAsn(15169));
    }

    private function clientExpecting(array $requests): RdapClient
    {
        $fetcher = $this->createMock(HttpFetcher::class);
        $fetcher->expects($this->exactly(count($requests)))->method('fetch')
            ->willReturnCallback(function (string $actualUrl) use (&$requests): HttpResponse {
                [$url, $response] = array_shift($requests);
                $this->assertSame($url, $actualUrl);
                if ($response instanceof RuntimeException) {
                    throw $response;
                }

                return $response;
            });
        $clock = fn (): int => $this->now;

        return new RdapClient($fetcher, new Bootstrap($fetcher, $this->cache, $clock), new RdapParser(), $this->cache, $clock);
    }

    private function response(string $name, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, $this->fixture($name));
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/fixtures/'.$name);
    }
}
