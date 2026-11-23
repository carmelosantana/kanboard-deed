<?php

namespace Kanboard\Plugin\Deed\Test;

use Kanboard\Plugin\Deed\Client\HttpFetcher;
use Kanboard\Plugin\Deed\Client\Resolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HttpFetcherTest extends TestCase
{
    private array $requests = [];
    private array $responses = [];
    private array $sleeps = [];
    private float $now = 1700000000;

    public static function blockedAddresses(): array
    {
        return array_map(static fn ($address) => [$address], [
            '127.0.0.1', '10.20.30.40', '172.31.1.1', '192.168.1.1', '169.254.1.1',
            '::1', 'fd00::1', 'fe80::1', '0.0.0.0', '100.64.0.1', '192.0.2.1',
            '192.88.99.1', '198.18.0.1', '239.1.1.1', '::ffff:10.0.0.1', 'ff02::1', '3fff::1',
        ]);
    }

    /** @dataProvider blockedAddresses */
    public function testRejectsNonpublicAddressEvenAlongsidePublicAddress(string $address): void
    {
        $fetcher = $this->fetcher(['rdap.example' => ['8.8.8.8', $address]]);

        $this->expectExceptionMessage('blocked_address');
        $fetcher->fetch('https://rdap.example/domain/example.com');
    }

    public static function unsafeUrls(): array
    {
        return [
            ['http://rdap.example/', 'invalid_url'],
            ['https://127.0.0.1/', 'blocked_address'],
            ['https://[::1]/', 'blocked_address'],
            ['https://rdap.example@elsewhere.example/', 'invalid_url'],
            ['https://2130706433/', 'invalid_url'],
            ['https://missing.example/', 'unresolved_host'],
        ];
    }

    /** @dataProvider unsafeUrls */
    public function testRejectsUnsafeUrl(string $url, string $error): void
    {
        $fetcher = $this->fetcher();

        $this->expectExceptionMessage($error);
        $fetcher->fetch($url);
    }

    public function testRechecksRedirectDestination(): void
    {
        $this->responses = [[302, ['Location: //private.example/']]];
        $fetcher = $this->fetcher(['private.example' => ['192.168.0.2']]);

        try {
            $fetcher->fetch('https://rdap.example/');
            self::fail('Expected the redirect destination to be rejected');
        } catch (RuntimeException $error) {
            self::assertSame('blocked_address', $error->getMessage());
            self::assertCount(1, $this->requests);
        }
    }

    public function testFollowsTwoRedirectsAndRefusesAThird(): void
    {
        $this->responses = [
            [301, ['Location: ../second/']],
            [307, ['Location: ?third']],
            [308, ['Location: /fourth']],
        ];

        try {
            $this->fetcher()->fetch('https://rdap.example/path/first');
            self::fail('Expected the third redirect to be rejected');
        } catch (RuntimeException $error) {
            self::assertSame('too_many_redirects', $error->getMessage());
            self::assertSame([
                'https://rdap.example/path/first',
                'https://rdap.example/second/',
                'https://rdap.example/second/?third',
            ], array_column($this->requests, CURLOPT_URL));
        }
    }

    public function testAcceptsOneMiBAndRejectsOneMoreByte(): void
    {
        $chunk = str_repeat('a', 512 * 1024);
        $this->responses = [[200, [], [$chunk, $chunk]], [200, [], [$chunk, $chunk, 'a']]];
        $fetcher = $this->fetcher();

        self::assertSame(1024 * 1024, strlen($fetcher->fetch('https://rdap.example/')->body));
        $this->expectExceptionMessage('body_too_large');
        $fetcher->fetch('https://rdap.example/');
    }

    public function testPinsCheckedAddressAndSetsCurlOptions(): void
    {
        $this->responses = [[304, ['ETag: "v1"']]];
        $response = $this->fetcher(['rdap.example' => ['2606:4700::1111']])->fetch(
            'https://RDAP.example:8443/',
            ['If-None-Match' => '"v1"', 'If-Modified-Since' => 'Tue, 14 Nov 2023 22:13:20 GMT']
        );

        $options = $this->requests[0];
        self::assertSame(['rdap.example:8443:[2606:4700::1111]'], $options[CURLOPT_RESOLVE]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame(10, $options[CURLOPT_TIMEOUT]);
        self::assertSame('Kanboard-Deed/1.0.0 (+https://board.example/)', $options[CURLOPT_USERAGENT]);
        self::assertSame([
            'Accept: application/rdap+json, application/json',
            'If-None-Match: "v1"',
            'If-Modified-Since: Tue, 14 Nov 2023 22:13:20 GMT',
        ], $options[CURLOPT_HTTPHEADER]);
        self::assertSame(304, $response->status);
        self::assertSame('"v1"', $response->header('etag'));
    }

    public function testReturnsRetryAfterAndSpacesRequestsPerHostWithinAnInstance(): void
    {
        $this->responses = [
            [429, ['Retry-After: 120']],
            [200, ['Retry-After: Tue, 14 Nov 2023 22:15:20 GMT']],
            [200],
        ];
        $fetcher = $this->fetcher(['other.example' => ['1.1.1.1']]);
        $first = $fetcher->fetch('https://RDAP.example/');
        $other = $fetcher->fetch('https://other.example/');

        self::assertSame(429, $first->status);
        self::assertSame(120, $first->retryAfter());
        self::assertSame(120, $other->retryAfter());
        self::assertSame([], $this->sleeps);

        $fetcher->fetch('https://rdap.example./');
        self::assertCount(1, $this->sleeps);
        self::assertGreaterThanOrEqual(2.0, $this->sleeps[0]);
    }

    public function testFetcherInstancesHaveIndependentRequestTiming(): void
    {
        $this->responses = [[200], [200]];
        $this->fetcher()->fetch('https://rdap.example/');
        $this->fetcher()->fetch('https://rdap.example/');

        self::assertSame([], $this->sleeps);
    }

    public function testCompletionClockFailurePreservesResponseAndTransferError(): void
    {
        $this->responses = [[200], [200, [], [str_repeat('a', 1024 * 1024 + 1)]]];
        $fetcher = $this->fetcher(clock: function (): float {
            if ($this->requests !== []) {
                throw new RuntimeException('clock_failed');
            }
            return $this->now;
        });

        self::assertSame(200, $fetcher->fetch('https://rdap.example/')->status);
        $this->expectExceptionMessage('body_too_large');
        $fetcher->fetch('https://rdap.example/');
    }

    public function testResolverKeepsIpv4WhenAaaaLookupFails(): void
    {
        $resolver = $this->getMockBuilder(Resolver::class)->onlyMethods(['lookupA', 'lookupAaaa'])->getMock();
        $resolver->expects(self::once())->method('lookupA')->with('rdap.example')->willReturn(['8.8.8.8']);
        $resolver->expects(self::once())->method('lookupAaaa')->with('rdap.example')->willReturn(false);

        self::assertSame(['8.8.8.8'], $resolver->resolve('rdap.example'));
    }

    public function testPublicIpv6LiteralNeedsNoDnsOverride(): void
    {
        $this->responses = [[200]];
        $this->fetcher()->fetch('https://[2606:4700:0:0:0:0:0:1111]/');

        self::assertSame('https://[2606:4700::1111]/', $this->requests[0][CURLOPT_URL]);
        self::assertArrayNotHasKey(CURLOPT_RESOLVE, $this->requests[0]);
    }

    private function fetcher(array $addresses = [], ?\Closure $clock = null): HttpFetcher
    {
        $resolver = $this->createMock(Resolver::class);
        $addresses += ['rdap.example' => ['8.8.8.8']];
        $resolver->method('resolve')->willReturnCallback(static fn ($host) => $addresses[$host] ?? []);

        return new HttpFetcher(
            '1.0.0',
            'https://board.example/',
            $resolver,
            function (array $options): int {
                $this->requests[] = $options;
                self::assertNotEmpty($this->responses, 'Unexpected transfer attempt');
                [$status, $headers, $chunks] = array_shift($this->responses) + [1 => [], 2 => []];
                $options[CURLOPT_HEADERFUNCTION](null, "HTTP/1.1 $status\r\n");
                foreach ($headers as $header) {
                    $options[CURLOPT_HEADERFUNCTION](null, "$header\r\n");
                }
                foreach ($chunks as $chunk) {
                    if ($options[CURLOPT_WRITEFUNCTION](null, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('http_transfer_failed');
                    }
                }
                return $status;
            },
            $clock ?? fn (): float => $this->now,
            function (float $seconds): void {
                $this->sleeps[] = $seconds;
                $this->now += $seconds;
            }
        );
    }
}
