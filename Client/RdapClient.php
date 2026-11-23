<?php

namespace Kanboard\Plugin\Deed\Client;

use Closure;
use Kanboard\Plugin\Deed\Model\CacheModel;
use Kanboard\Plugin\Deed\Model\RdapParser;
use RuntimeException;

class RdapClient
{
    private const SUCCESS_TTL = 86400;
    private const NOT_FOUND_TTL = 3600;

    private readonly Closure $clock;

    public function __construct(
        private readonly HttpFetcher $fetcher,
        private readonly Bootstrap $bootstrap,
        private readonly RdapParser $parser,
        private readonly CacheModel $cache,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** Watchlist refreshes can bypass the on-demand cache and repopulate it. */
    public function lookupDomain(string $domain, bool $useCache = true): array
    {
        return $this->lookup('domain', self::normaliseDomain($domain), $useCache);
    }

    /** Read the last cached sources, even after the lookup TTL, without making a request. */
    public function cachedDomainSource(string $name): ?array
    {
        $cached = $this->cache->get(self::lookupKey('domain', self::normaliseDomain($name)));
        $record = json_decode($cached['body'] ?? '', true);
        if (($record['status'] ?? null) !== 'ok') {
            return null;
        }

        return [
            'rdap_url' => $record['rdap_url'] ?? null,
            'related_url' => ($record['referral_error'] ?? null) === null ? ($record['related_url'] ?? null) : null,
        ];
    }

    public function lookupIp(string $ip): array
    {
        $ip = filter_var(trim($ip), FILTER_VALIDATE_IP);
        if ($ip === false) {
            return ['status' => 'unsupported'];
        }

        return $this->lookup('ip', inet_ntop(inet_pton($ip)));
    }

    public function lookupAsn(int|string $asn): array
    {
        $asn = trim((string) $asn);
        if (!preg_match('/^(?:AS)?([0-9]+)$/iD', $asn, $parts) || (int) $parts[1] > 4294967295) {
            return ['status' => 'unsupported'];
        }

        return $this->lookup('autnum', (string) (int) $parts[1]);
    }

    public static function effectiveExpiry(?int $registryExpiresAt, ?int $registrarExpiresAt): ?int
    {
        if ($registryExpiresAt === null || $registrarExpiresAt === null) {
            return $registryExpiresAt ?? $registrarExpiresAt;
        }

        return min($registryExpiresAt, $registrarExpiresAt);
    }

    private static function normaliseDomain(string $domain): string
    {
        return strtolower(rtrim(trim($domain), '.'));
    }

    private static function lookupKey(string $kind, string $query): string
    {
        return 'lookup:'.$kind.':'.hash('sha256', $query);
    }

    private function lookup(string $kind, string $query, bool $useCache = true): array
    {
        $key = self::lookupKey($kind, $query);
        $cached = $this->cache->get($key);
        if ($useCache && $cached !== null && $cached['expires_at'] > ($this->clock)()) {
            $result = json_decode($cached['body'], true);
            if (is_array($result)) {
                return $result;
            }
        }

        try {
            $baseUrls = match ($kind) {
                'domain' => $this->bootstrap->baseUrlsForDomain($query),
                'ip' => $this->bootstrap->baseUrlsForIp($query),
                'autnum' => $this->bootstrap->baseUrlsForAsn((int) $query),
            };
        } catch (RuntimeException $error) {
            return ['status' => 'error', 'error' => $error->getMessage()];
        }

        if ($baseUrls === []) {
            return ['status' => 'unsupported'];
        }

        // Colons are valid path characters in the canonical IPv6 address.
        $path = $kind === 'ip' ? $query : rawurlencode($query);
        $url = rtrim($baseUrls[0], '/').'/'.$kind.'/'.$path;
        $response = null;
        try {
            $response = $this->fetcher->fetch($url);
            if ($response->status === 404) {
                $result = ['status' => 'not_found', 'rdap_url' => $url];
            } else {
                $record = $this->decodeRecord($response, $kind);
                $fields = match ($kind) {
                    'domain' => $this->domainFields($record),
                    'ip' => $this->parser->parseNetwork($record),
                    'autnum' => $this->parser->parseAutnum($record),
                };
                $result = ['status' => 'ok', 'rdap_url' => $url] + $fields;
            }
        } catch (RuntimeException $error) {
            return [
                'status' => 'error',
                'rdap_url' => $url,
                'error' => $error->getMessage(),
                'retry_after' => $response?->retryAfter(),
            ];
        }

        $now = ($this->clock)();
        $ttl = $response->status === 404 ? self::NOT_FOUND_TTL : self::SUCCESS_TTL;
        $this->cache->save([
            'cache_key' => $key,
            'url' => $url,
            'body' => json_encode($result, JSON_THROW_ON_ERROR),
            'etag' => null,
            'last_modified' => null,
            'fetched_at' => $now,
            'expires_at' => $now + $ttl,
            'http_status' => $response->status,
        ], $cached);

        return $result;
    }

    private function domainFields(array $record): array
    {
        $fields = $this->parser->parseDomain($record);
        $fields['referral_error'] = null;
        if ($fields['related_url'] === null) {
            return $fields;
        }

        try {
            $response = $this->fetcher->fetch($fields['related_url']);
            $referral = $this->parser->parseDomain($this->decodeRecord($response, 'domain'));
        } catch (RuntimeException $error) {
            $fields['referral_error'] = $error->getMessage();
            return $fields;
        }

        foreach ($referral as $key => $value) {
            if ($value !== null && ($key === 'registrar_expires_at' || $fields[$key] === null)) {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    private function decodeRecord(HttpResponse $response, string $kind): array
    {
        if ($response->status !== 200) {
            throw new RuntimeException('http_'.$response->status);
        }

        $record = json_decode($response->body, true);
        $objectClass = $kind === 'ip' ? 'ip network' : $kind;
        if (($record['objectClassName'] ?? null) !== $objectClass || isset($record['errorCode'])) {
            throw new RuntimeException('invalid_rdap_response');
        }

        return $record;
    }
}
