<?php

namespace Kanboard\Plugin\Deed\Model;

use DateMalformedStringException;
use DateTimeImmutable;

class RdapParser
{
    public function parseDomain(array $record): array
    {
        $events = (array) ($record['events'] ?? []);

        return [
            'registered_at' => $this->eventTimestamp($events, 'registration'),
            'registry_expires_at' => $this->eventTimestamp($events, 'expiration'),
            'registrar_expires_at' => $this->eventTimestamp($events, 'registrar expiration'),
            'registrar' => $this->contactValue((array) ($record['entities'] ?? []), 'registrar', 'fn'),
            'statuses' => $record['status'] ?? null,
            'nameservers' => $this->nameservers($record['nameservers'] ?? null),
            'related_url' => $this->relatedUrl((array) ($record['links'] ?? [])),
        ];
    }

    public function parseNetwork(array $record): array
    {
        $entities = (array) ($record['entities'] ?? []);

        return [
            'name' => $record['name'] ?? null,
            'handle' => $record['handle'] ?? null,
            'range' => $this->formatRange($record['startAddress'] ?? null, $record['endAddress'] ?? null),
            'country' => $record['country'] ?? null,
            'registrant' => $this->contactValue($entities, 'registrant', 'fn'),
            'abuse_email' => $this->contactValue($entities, 'abuse', 'email'),
        ];
    }

    public function parseAutnum(array $record): array
    {
        $entities = (array) ($record['entities'] ?? []);

        return [
            'name' => $record['name'] ?? null,
            'handle' => $record['handle'] ?? null,
            'range' => $this->formatRange($record['startAutnum'] ?? null, $record['endAutnum'] ?? null),
            'country' => $record['country'] ?? null,
            'registrant' => $this->contactValue($entities, 'registrant', 'fn'),
            'abuse_email' => $this->contactValue($entities, 'abuse', 'email'),
        ];
    }

    private function eventTimestamp(array $events, string $action): ?int
    {
        foreach ($events as $event) {
            if (($event['eventAction'] ?? null) !== $action) {
                continue;
            }

            $date = $event['eventDate'] ?? null;
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/iD', $date)) {
                continue;
            }

            try {
                $timestamp = new DateTimeImmutable($date);
            } catch (DateMalformedStringException) {
                continue;
            }

            if (DateTimeImmutable::getLastErrors() === false) {
                return $timestamp->getTimestamp();
            }
        }

        return null;
    }

    private function contactValue(array $entities, string $role, string $property): ?string
    {
        foreach ($entities as $entity) {
            if (in_array($role, (array) ($entity['roles'] ?? []), true)) {
                foreach ((array) ($entity['vcardArray'][1] ?? []) as $field) {
                    $value = $field[3] ?? null;
                    if (($field[0] ?? null) === $property && is_string($value) && $value !== '') {
                        return $value;
                    }
                }
            }

            $nested = (array) ($entity['entities'] ?? []);
            if ($nested !== []) {
                $value = $this->contactValue($nested, $role, $property);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function nameservers(mixed $nameservers): ?array
    {
        if (!is_array($nameservers)) {
            return null;
        }

        $names = [];
        foreach ($nameservers as $nameserver) {
            $name = $nameserver['ldhName'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = strtolower($name);
            }
        }

        sort($names, SORT_STRING);

        return $names;
    }

    private function relatedUrl(array $links): ?string
    {
        foreach ($links as $link) {
            $href = $link['href'] ?? null;
            if (($link['rel'] ?? null) === 'related' && is_string($href) && $href !== '') {
                return $href;
            }
        }

        return null;
    }

    private function formatRange(mixed $start, mixed $end): ?string
    {
        if ((!is_string($start) && !is_int($start)) || (!is_string($end) && !is_int($end))) {
            return null;
        }

        return (string) $start === (string) $end ? (string) $start : $start.' - '.$end;
    }
}
