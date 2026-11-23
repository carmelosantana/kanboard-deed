<?php

namespace Kanboard\Plugin\Deed\Client;

use Closure;
use RuntimeException;

class HttpFetcher
{
    private const MAX_BODY_BYTES = 1024 * 1024;
    private const MAX_REDIRECTS = 2;
    private const HOST_INTERVAL = 2.0;

    private readonly Closure $transfer;
    private readonly Closure $clock;
    private readonly Closure $sleeper;
    private readonly string $lockDirectory;

    /**
     * The transfer executes cURL options, invokes their callbacks and returns
     * the HTTP status. The clock and sleeper use seconds.
     */
    public function __construct(
        private readonly string $version,
        private readonly string $applicationUrl,
        private readonly Resolver $resolver = new Resolver(),
        ?Closure $transfer = null,
        ?Closure $clock = null,
        ?Closure $sleeper = null,
        ?string $lockDirectory = null
    ) {
        $this->transfer = $transfer ?? $this->executeCurl(...);
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleeper = $sleeper ?? static fn (float $seconds) => usleep((int) ceil($seconds * 1000000));
        $this->lockDirectory = $lockDirectory ?? (defined('DATA_DIR')
            ? DATA_DIR.'/deed-http'
            : sys_get_temp_dir().'/kanboard-deed-'.hash('sha256', __DIR__));
    }

    /** @param array<string, string> $headers Optional cache validators. */
    public function fetch(string $url, array $headers = []): HttpResponse
    {
        $requestHeaders = ['Accept: application/rdap+json, application/json'];
        $headers = array_change_key_case($headers);
        foreach (['If-None-Match', 'If-Modified-Since'] as $name) {
            $value = $headers[strtolower($name)] ?? null;
            if ($value !== null) {
                if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    throw new RuntimeException('invalid_header');
                }
                $requestHeaders[] = $name.': '.$value;
            }
        }

        for ($redirects = 0; ; ++$redirects) {
            [$url, $host, $port] = $this->httpsTarget($url);
            $address = $this->checkedAddress($host);
            $response = $this->request($url, $host, $port, $address, $requestHeaders);
            $location = $response->header('Location');
            if (!in_array($response->status, [301, 302, 303, 307, 308], true) || $location === null) {
                return $response;
            }
            if ($redirects === self::MAX_REDIRECTS) {
                throw new RuntimeException('too_many_redirects');
            }
            $url = $this->redirectUrl($url, $location);
        }
    }

    /** @return array{string, string, int} Canonical URL, host and port. */
    private function httpsTarget(string $url): array
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) || str_contains($url, "\\")) {
            throw new RuntimeException('invalid_url');
        }

        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('invalid_url');
        }

        $host = strtolower($parts['host']);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ip = substr($host, 1, -1);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new RuntimeException('invalid_url');
            }
            $host = '['.inet_ntop(inet_pton($ip)).']';
        } else {
            $host = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                && (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                    || str_ends_with($host, '.')
                    // Reject alternate numeric spellings that cURL could interpret as IPv4 literals.
                    || preg_match('/^(?:0x[0-9a-f]*|[0-9]+)(?:\.(?:0x[0-9a-f]*|[0-9]+))*$/D', $host))) {
                throw new RuntimeException('invalid_url');
            }
        }

        $port = $parts['port'] ?? 443;
        if ($port === 0) {
            throw new RuntimeException('invalid_url');
        }
        $authority = 'https://'.$host.($port === 443 ? '' : ':'.$port);
        $path = $this->normalisePath($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return [$authority.$path.$query, $host, $port];
    }

    private function checkedAddress(string $host): string
    {
        $literal = trim($host, '[]');
        $addresses = filter_var($literal, FILTER_VALIDATE_IP) !== false ? [$literal] : $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new RuntimeException('unresolved_host');
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                throw new RuntimeException('blocked_address');
            }
            $bytes = inet_pton($address);
            $firstByte = ord($bytes[0]);
            $isIpv4 = strlen($bytes) === 4;
            // PHP's global-range filter omits these special-use ranges.
            $blockedIpv4 = $isIpv4 && ($firstByte >= 224 || str_starts_with($bytes, "\xc0\x58\x63"));
            $blockedIpv6 = !$isIpv4 && (($firstByte & 0xe0) !== 0x20 || str_starts_with($bytes, "\x3f\xff"));
            if ($blockedIpv4 || $blockedIpv6) {
                throw new RuntimeException('blocked_address');
            }
        }

        return $addresses[0];
    }

    private function request(string $url, string $host, int $port, string $address, array $requestHeaders): HttpResponse
    {
        $body = '';
        $headers = [];
        $bodyTooLarge = false;
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'Kanboard-Deed/'.$this->version.' (+'.$this->applicationUrl.')',
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower($name)] = trim($value, " \t\r\n");
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$bodyTooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY_BYTES) {
                    $bodyTooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        // IP literals already identify the checked destination without a DNS override.
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false) {
            $pinnedAddress = str_contains($address, ':') ? '['.$address.']' : $address;
            $options[CURLOPT_RESOLVE] = [$host.':'.$port.':'.$pinnedAddress];
        }

        $lock = $this->lockHost($host);
        try {
            $status = ($this->transfer)($options);
        } catch (RuntimeException $error) {
            if ($bodyTooLarge) {
                throw new RuntimeException('body_too_large', 0, $error);
            }
            throw $error;
        } finally {
            // Record completion before releasing the lock so a CLI or web worker waits its turn.
            rewind($lock);
            $timestamp = sprintf('%.6F', ($this->clock)());
            if (!ftruncate($lock, 0) || fwrite($lock, $timestamp) !== strlen($timestamp) || !fflush($lock)) {
                fclose($lock);
                throw new RuntimeException('host_lock_failed');
            }
            fclose($lock);
        }

        return new HttpResponse($status, $body, $headers, (int) ($this->clock)());
    }

    /** @return resource */
    private function lockHost(string $host)
    {
        if (!is_dir($this->lockDirectory)
            && !@mkdir($this->lockDirectory, 0700)
            && !is_dir($this->lockDirectory)) {
            throw new RuntimeException('host_lock_failed');
        }
        $permissions = @fileperms($this->lockDirectory);
        if ($permissions === false || ($permissions & 0077) !== 0 || is_link($this->lockDirectory)) {
            throw new RuntimeException('host_lock_failed');
        }

        $lock = @fopen($this->lockDirectory.'/'.hash('sha256', $host).'.lock', 'c+');
        if ($lock === false) {
            throw new RuntimeException('host_lock_failed');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('host_lock_failed');
            }
            $lastRequest = stream_get_contents($lock);
            if ($lastRequest === false) {
                throw new RuntimeException('host_lock_failed');
            }
            if ($lastRequest !== '') {
                $elapsed = max(0.0, ($this->clock)() - (float) $lastRequest);
                if ($elapsed < self::HOST_INTERVAL) {
                    ($this->sleeper)(self::HOST_INTERVAL - $elapsed);
                }
            }
            return $lock;
        } catch (\Throwable $error) {
            fclose($lock);
            throw $error;
        }
    }

    private function executeCurl(array $options): int
    {
        $curl = curl_init();
        if ($curl === false) {
            throw new RuntimeException('http_transfer_failed');
        }

        if (!curl_setopt_array($curl, $options) || curl_exec($curl) === false) {
            throw new RuntimeException('http_transfer_failed');
        }

        return curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    }

    private function redirectUrl(string $url, string $location): string
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $location)) {
            return $location;
        }
        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }

        $parts = parse_url($url);
        $origin = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if ($location === '' || str_starts_with($location, '#')) {
            return $url;
        }
        if (str_starts_with($location, '?')) {
            return $origin.$parts['path'].$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $directory = substr($parts['path'], 0, strrpos($parts['path'], '/') + 1);
        return $origin.$directory.$location;
    }

    private function normalisePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', substr($path, 1)) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }
        if (str_ends_with($path, '/.') || str_ends_with($path, '/..')) {
            $segments[] = '';
        }

        return '/'.implode('/', $segments);
    }
}
