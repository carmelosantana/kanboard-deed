<?php

namespace Kanboard\Plugin\Deed\Client;

use Closure;
use Kanboard\Plugin\Deed\Model\CacheModel;
use RuntimeException;

final class Bootstrap
{
    private const BASE_URL = 'https://data.iana.org/rdap/';
    private const DEFAULT_MAX_AGE = 86400;

    private readonly Closure $clock;

    public function __construct(
        private readonly HttpFetcher $fetcher,
        private readonly CacheModel $cache,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @return list<string> */
    public function baseUrlsForDomain(string $domain): array
    {
        $domain = strtolower(rtrim($domain, '.'));
        $longest = 0;
        $matches = [];
        foreach ($this->services('dns') as [$suffixes, $urls]) {
            foreach ($suffixes as $suffix) {
                $suffix = strtolower($suffix);
                if (strlen($suffix) > $longest && ($domain === $suffix || str_ends_with($domain, '.'.$suffix))) {
                    $longest = strlen($suffix);
                    $matches = $urls;
                }
            }
        }

        return $matches;
    }

    /** @return list<string> */
    public function baseUrlsForIp(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [];
        }

        $address = inet_pton($ip);
        $size = strlen($address);
        $longest = -1;
        $matches = [];
        foreach ($this->services($size === 4 ? 'ipv4' : 'ipv6') as [$prefixes, $urls]) {
            foreach ($prefixes as $prefix) {
                [$network, $length] = array_pad(explode('/', $prefix, 2), 2, '');
                if (!ctype_digit($length) || (int) $length <= $longest || (int) $length > $size * 8) {
                    continue;
                }

                $network = inet_pton($network);
                if ($network !== false && strlen($network) === $size && self::matchesPrefix($address, $network, (int) $length)) {
                    $longest = (int) $length;
                    $matches = $urls;
                }
            }
        }

        return $matches;
    }

    /** @return list<string> */
    public function baseUrlsForAsn(int $asn): array
    {
        foreach ($this->services('asn') as [$ranges, $urls]) {
            foreach ($ranges as $range) {
                [$start, $end] = array_pad(explode('-', $range, 2), 2, null);
                $end ??= $start;
                if (ctype_digit($start) && ctype_digit($end) && $asn >= (int) $start && $asn <= (int) $end) {
                    return $urls;
                }
            }
        }

        return [];
    }

    private static function matchesPrefix(string $address, string $network, int $length): bool
    {
        $bytes = intdiv($length, 8);
        $bits = $length % 8;
        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        return $bits === 0 || (ord($address[$bytes]) >> (8 - $bits)) === (ord($network[$bytes]) >> (8 - $bits));
    }

    /** @return list<array{list<string>, list<string>}> */
    private function services(string $kind): array
    {
        $key = 'bootstrap:'.$kind;
        $url = self::BASE_URL.$kind.'.json';
        $entry = $this->cache->get($key);
        $cached = $entry === null ? null : $this->decodeServices($entry['body']);
        if ($cached !== null && $entry['expires_at'] > ($this->clock)()) {
            return $cached;
        }

        $headers = array_filter([
            'If-None-Match' => $cached === null ? null : $entry['etag'],
            'If-Modified-Since' => $cached === null ? null : $entry['last_modified'],
        ], static fn (?string $value): bool => $value !== null);
        try {
            $response = $this->fetcher->fetch($url, $headers);
        } catch (RuntimeException $error) {
            return $cached ?? throw new RuntimeException('bootstrap_unavailable', 0, $error);
        }

        $revalidated = $response->status === 304 && $cached !== null;
        $services = $revalidated ? $cached : ($response->status === 200 ? $this->decodeServices($response->body) : null);
        if ($services === null) {
            return $cached ?? throw new RuntimeException('bootstrap_unavailable');
        }

        $now = ($this->clock)();
        $saved = $this->cache->save([
            'cache_key' => $key,
            'url' => $url,
            'body' => $revalidated ? null : $response->body,
            'etag' => $response->header('ETag') ?? ($revalidated ? $entry['etag'] : null),
            'last_modified' => $response->header('Last-Modified') ?? ($revalidated ? $entry['last_modified'] : null),
            'fetched_at' => $now,
            'expires_at' => $now + self::maxAge($response),
            'http_status' => $response->status,
        ], $entry);

        if (!$saved) {
            $current = $this->cache->get($key);
            if ($current !== null) {
                return $this->decodeServices($current['body']) ?? $services;
            }
        }

        return $services;
    }

    private static function maxAge(HttpResponse $response): int
    {
        $header = $response->header('Cache-Control') ?? '';
        if (preg_match('/(?:^|,)\s*max-age\s*=\s*(?:"([0-9]+)"|([0-9]+))\s*(?=,|$)/i', $header, $parts)) {
            $seconds = filter_var($parts[2] ?? $parts[1], FILTER_VALIDATE_INT);
            return $seconds === false ? self::DEFAULT_MAX_AGE : $seconds;
        }

        return self::DEFAULT_MAX_AGE;
    }

    /** @return list<array{list<string>, list<string>}>|null */
    private function decodeServices(string $body): ?array
    {
        $groups = json_decode($body)->services ?? null;
        if (!is_array($groups) || $groups === []) {
            return null;
        }

        $services = [];
        foreach ($groups as $group) {
            if (!is_array($group) || count($group) !== 2
                || !self::isStringList($group[0]) || !self::isStringList($group[1])) {
                return null;
            }

            [$keys, $urls] = $group;
            $httpsUrls = array_filter($urls, static fn (string $url): bool => str_starts_with(strtolower($url), 'https://'));
            $services[] = [$keys, array_values($httpsUrls)];
        }

        return $services;
    }

    private static function isStringList(mixed $values): bool
    {
        if (!is_array($values) || $values === []) {
            return false;
        }
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                return false;
            }
        }

        return true;
    }
}
