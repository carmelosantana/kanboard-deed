<?php

namespace Kanboard\Plugin\Deed\Client;

final class HttpResponse
{
    /** @param array<string, string> $headers HTTP field names and values */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        private readonly ?int $receivedAt = null
    ) {
    }

    public function header(string $name): ?string
    {
        return array_change_key_case($this->headers)[strtolower($name)] ?? null;
    }

    public function retryAfter(): ?int
    {
        $value = $this->header('Retry-After');
        if ($value === null) {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }

        $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));

        return $date === false ? null : max(0, $date->getTimestamp() - ($this->receivedAt ?? time()));
    }
}
