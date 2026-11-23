<?php

namespace Kanboard\Plugin\Deed\Test;

use Kanboard\Plugin\Deed\Client\Bootstrap;
use Kanboard\Plugin\Deed\Client\HttpFetcher;
use Kanboard\Plugin\Deed\Client\HttpResponse;
use Kanboard\Plugin\Deed\Model\CacheModel;
use KanboardTests\units\Base;
use PDO;
use RuntimeException;
use function Kanboard\Plugin\Deed\Schema\version_1;

require_once 'tests/units/Base.php';

class BootstrapTest extends Base
{
    private const COM_URLS = ['https://rdap.verisign.com/com/v1/'];
    private const VALIDATORS = [
        'If-None-Match' => 'W/"original"',
        'If-Modified-Since' => 'Fri, 20 Nov 2026 23:00:03 GMT',
    ];

    private PDO $pdo;
    private int $now = 1795219200;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        $this->pdo = $this->container['db']->getConnection();
        version_1($this->pdo);
    }

    public function testDomainUsesLongestLabelSuffixAndIgnoresUnknownTld(): void
    {
        $body = $this->fixture('dns', [[['co.uk'], ['https://rdap.example/co.uk/']]]);

        $bootstrap = $this->bootstrapExpecting('dns', new HttpResponse(200, $body));

        $this->assertSame(['https://rdap.example/co.uk/'], $bootstrap->baseUrlsForDomain('a.b.example.co.uk'));
        $this->assertSame(['https://rdap.nominet.uk/uk/'], $bootstrap->baseUrlsForDomain('example.notco.uk'));
        $this->assertSame([], $bootstrap->baseUrlsForDomain('example.unknown-tld'));
        $this->assertSame($this->now + 86400, (int) $this->dnsRow()['expires_at']);
    }

    public function testIpv4UsesLongestPrefix(): void
    {
        $body = $this->fixture('ipv4', [
            [['8.8.8.128/25'], ['https://rdap.example/upper/']],
            [['8.8.8.0/25'], ['https://rdap.example/lower/']],
        ]);

        $bootstrap = $this->bootstrapExpecting('ipv4', new HttpResponse(200, $body));

        $this->assertSame(['https://rdap.example/lower/'], $bootstrap->baseUrlsForIp('8.8.8.8'));
        $this->assertSame(['https://rdap.arin.net/registry/'], $bootstrap->baseUrlsForIp('8.9.0.1'));
    }

    public function testIpv6UsesLongestPrefix(): void
    {
        $body = $this->fixture('ipv6', [[['2001:4210::/28'], ['https://rdap.example/v6/']]]);

        $bootstrap = $this->bootstrapExpecting('ipv6', new HttpResponse(200, $body));

        $this->assertSame(['https://rdap.example/v6/'], $bootstrap->baseUrlsForIp('2001:4218::1'));
        $this->assertSame(['https://rdap.afrinic.net/rdap/'], $bootstrap->baseUrlsForIp('2001:4208::1'));
    }

    public function testAsnUsesContainingRangeAndSupportsSingleton(): void
    {
        $bootstrap = $this->bootstrapExpecting('asn', new HttpResponse(200, $this->fixture('asn')));

        $this->assertSame(['https://rdap.arin.net/registry/'], $bootstrap->baseUrlsForAsn(15169));
        $this->assertSame(['https://rdap.db.ripe.net/'], $bootstrap->baseUrlsForAsn(2043));
    }

    public function testExpiredCopyIsConditionallyRevalidatedWithoutReplacingItsBody(): void
    {
        $body = $this->primeDnsCache();
        $bootstrap = $this->bootstrapExpecting('dns', new HttpResponse(304, 'ignored', [
            'Cache-Control' => 'public, max-age=120',
        ]), self::VALIDATORS);

        $this->assertSame(self::COM_URLS, $bootstrap->baseUrlsForDomain('example.com'));
        $row = $this->dnsRow();
        $this->assertSame($body, $row['body']);
        $this->assertSame($this->now + 120, (int) $row['expires_at']);
        $this->assertSame(self::VALIDATORS['If-None-Match'], $row['etag']);
        $this->assertSame(self::VALIDATORS['If-Modified-Since'], $row['last_modified']);
        $this->assertSame(self::COM_URLS, $bootstrap->baseUrlsForDomain('example.com'));
    }

    public function testSuccessfulRevalidationReplacesTheCachedDocumentAndValidators(): void
    {
        $this->primeDnsCache();
        $body = $this->fixture('dns', [[['example'], ['https://rdap.example/']]]);

        $bootstrap = $this->bootstrapExpecting('dns', new HttpResponse(200, $body, ['ETag' => '"new"']), self::VALIDATORS);

        $this->assertSame(['https://rdap.example/'], $bootstrap->baseUrlsForDomain('a.example'));
        $row = $this->dnsRow();
        $this->assertSame($body, $row['body']);
        $this->assertSame('"new"', $row['etag']);
        $this->assertNull($row['last_modified']);
        $this->assertSame($this->now + 86400, (int) $row['expires_at']);
    }

    /** @dataProvider failedResponses */
    public function testFailedRefreshKeepsEntireLastGoodRow(HttpResponse|RuntimeException $result): void
    {
        $this->primeDnsCache();
        $row = $this->dnsRow();
        $bootstrap = $this->bootstrapExpecting('dns', $result, self::VALIDATORS);

        $this->assertSame(self::COM_URLS, $bootstrap->baseUrlsForDomain('example.com'));
        $this->assertSame($row, $this->dnsRow());
    }

    /** @dataProvider failedResponses */
    public function testFailedFetchWithoutCopyThrows(HttpResponse|RuntimeException $result): void
    {
        $bootstrap = $this->bootstrapExpecting('dns', $result);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bootstrap_unavailable');
        $bootstrap->baseUrlsForDomain('example.com');
    }

    public static function failedResponses(): iterable
    {
        yield 'invalid JSON' => [new HttpResponse(200, '{')];
        yield 'invalid services' => [new HttpResponse(200, '{"services":[[["com"],[null]]]}')];
        yield 'server error' => [new HttpResponse(503, '')];
        yield 'transport failure' => [new RuntimeException('http_transfer_failed')];
    }

    private function bootstrapExpecting(string $kind, HttpResponse|RuntimeException $result, array $headers = []): Bootstrap
    {
        $fetcher = $this->createMock(HttpFetcher::class);
        $call = $fetcher->expects($this->once())->method('fetch')
            ->with('https://data.iana.org/rdap/'.$kind.'.json', $headers);
        if ($result instanceof RuntimeException) {
            $call->willThrowException($result);
        } else {
            $call->willReturn($result);
        }

        return new Bootstrap($fetcher, new CacheModel($this->pdo), fn (): int => $this->now);
    }

    private function primeDnsCache(): string
    {
        $body = $this->fixture('dns');
        $bootstrap = $this->bootstrapExpecting('dns', new HttpResponse(200, $body, [
            'Cache-Control' => 'public, max-age=60',
            'ETag' => self::VALIDATORS['If-None-Match'],
            'Last-Modified' => self::VALIDATORS['If-Modified-Since'],
        ]));
        $this->assertSame(self::COM_URLS, $bootstrap->baseUrlsForDomain('example.com'));
        $this->assertSame($this->now + 60, (int) $this->dnsRow()['expires_at']);
        $this->now += 61;

        return $body;
    }

    private function dnsRow(): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM deed_cache WHERE cache_key = ?');
        $statement->execute(['bootstrap:dns']);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    private function fixture(string $kind, array $extraServices = []): string
    {
        $body = file_get_contents(__DIR__.'/fixtures/iana-'.$kind.'.json');
        if ($extraServices === []) {
            return $body;
        }

        $document = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $document['services'] = array_merge($document['services'], $extraServices);

        return json_encode($document, JSON_THROW_ON_ERROR);
    }
}
