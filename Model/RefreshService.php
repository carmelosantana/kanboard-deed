<?php

namespace Kanboard\Plugin\Deed\Model;

use Closure;
use Kanboard\Plugin\Deed\Client\RdapClient;
use PDO;
use Throwable;

final class RefreshService
{
    private const DAY = 86400;
    private const BACKOFF = [3600, 21600, self::DAY];

    private readonly Closure $clock;

    /** @param ?Closure(array, int): void $evaluateRenewal */
    public function __construct(
        private readonly PDO $pdo,
        private readonly DomainModel $domains,
        private readonly SnapshotModel $snapshots,
        private readonly RdapClient $rdap,
        private readonly int $batchSize = 25,
        private readonly ?Closure $evaluateRenewal = null,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function isStale(array $domain): bool
    {
        $since = $domain['last_success_at'] ?? $domain['created_at'] ?? null;

        return $since !== null && $since < ($this->clock)() - 3 * self::DAY;
    }

    /** Return the updated domain, or null if the refresh could no longer be applied. */
    public function refresh(int $domainId): ?array
    {
        $domain = $this->domains->get($domainId);
        if ($domain === null) {
            return null;
        }

        $updated = $this->refreshDomain($domain);
        $this->evaluateRenewal($updated);

        return $updated;
    }

    /** @return array{refreshed: int, failed: int, skipped: int} */
    public function sweep(?int $limit = null): array
    {
        $counts = ['refreshed' => 0, 'failed' => 0, 'skipped' => 0];
        $limit = min($limit ?? $this->batchSize, $this->batchSize);
        if ($limit <= 0) {
            return $counts;
        }

        $now = ($this->clock)();
        $statement = $this->pdo->prepare(
            'SELECT id FROM deed_domains WHERE next_check_at <= ? ORDER BY next_check_at, id LIMIT ?'
        );
        $statement->bindValue(1, $now, PDO::PARAM_INT);
        $statement->bindValue(2, $limit, PDO::PARAM_INT);
        $statement->execute();

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                $status = $this->sweepDomain((int) $id, $now);
            } catch (Throwable $exception) {
                error_log("Deed sweep failed for domain {$id}: ".$exception->getMessage());
                $status = 'error';
            }

            ++$counts[match ($status) {
                'ok', 'not_found' => 'refreshed',
                'error' => 'failed',
                default => 'skipped',
            }];
        }

        return $counts;
    }

    private function sweepDomain(int $domainId, int $dueAt): ?string
    {
        $domain = $this->domains->get($domainId);
        if ($domain === null || $domain['next_check_at'] > $dueAt) {
            return null;
        }

        $updated = null;
        try {
            $updated = $this->refreshDomain($domain);
            $this->evaluateRenewal($updated);

            return $updated['status'] ?? null;
        } catch (Throwable $exception) {
            // A lookup error already has a retry scheduled if renewal evaluation also failed.
            if (($updated['status'] ?? null) === 'error') {
                throw $exception;
            }

            $failed = $this->storeResult($updated ?? $domain, [
                'status' => 'error',
                'error' => $exception->getMessage() ?: $exception::class,
            ], ($this->clock)());
            if ($updated === null) {
                $this->evaluateRenewal($failed);
            }

            return 'error';
        }
    }

    private function refreshDomain(array $domain): ?array
    {
        $result = $this->rdap->lookupDomain($domain['name'], false);

        return $this->storeResult($domain, $result, ($this->clock)());
    }

    private function storeResult(array $domain, array $result, int $now): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $current = $this->lockDomain((int) $domain['id']);
            if ($current === null || (string) $current['last_checked_at'] !== (string) $domain['last_checked_at']) {
                $this->pdo->commit();
                return null;
            }

            $updated = $this->apply($current, $result, $now);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $updated;
    }

    private function evaluateRenewal(?array $domain): void
    {
        if ($domain !== null && $this->evaluateRenewal !== null) {
            ($this->evaluateRenewal)($domain, (int) $domain['last_checked_at']);
        }
    }

    private function lockDomain(int $id): ?array
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            // Acquire the write lock before reading inside the deferred transaction.
            $this->pdo->prepare('UPDATE deed_domains SET id = id WHERE id = ?')->execute([$id]);
            return $this->domains->get($id);
        }

        $statement = $this->pdo->prepare('SELECT * FROM deed_domains WHERE id = ? FOR UPDATE');
        $statement->execute([$id]);

        $domain = $statement->fetch(PDO::FETCH_ASSOC);
        return $domain === false ? null : $domain;
    }

    private function apply(array $domain, array $result, int $now): array
    {
        $status = $result['status'];
        $failCount = $status === 'error' ? $domain['fail_count'] + 1 : 0;
        $delay = match ($status) {
            'error' => min(self::DAY, max(
                self::BACKOFF[min($failCount, count(self::BACKOFF)) - 1],
                $result['retry_after'] ?? 0
            )),
            'unsupported' => 7 * self::DAY,
            default => self::DAY,
        };

        $changes = [
            'status' => $status,
            'last_checked_at' => $now,
            'next_check_at' => $now + min($delay, PHP_INT_MAX - $now),
            'fail_count' => $failCount,
            'last_error' => $result['error'] ?? $result['referral_error'] ?? null,
        ];

        $snapshot = null;
        if ($status === 'ok') {
            $tracked = $this->trackedFields($domain, $result);
            $data = json_encode($tracked, JSON_THROW_ON_ERROR);
            $fingerprint = hash('sha256', $data);
            if ($fingerprint !== $domain['fingerprint']) {
                $snapshot = $data;
            }

            $tracked['statuses'] = json_encode($tracked['statuses'], JSON_THROW_ON_ERROR);
            $tracked['nameservers'] = json_encode($tracked['nameservers'], JSON_THROW_ON_ERROR);
            $changes += $tracked + [
                'registered_at' => $result['registered_at'] ?? $domain['registered_at'],
                'rdap_url' => $result['rdap_url'] ?? $domain['rdap_url'],
                'last_success_at' => $now,
                'fingerprint' => $fingerprint,
            ];
        }

        $assignments = implode(' = ?, ', array_keys($changes)).' = ?';
        $statement = $this->pdo->prepare('UPDATE deed_domains SET '.$assignments.' WHERE id = ?');
        $statement->execute([...array_values($changes), $domain['id']]);

        if ($snapshot !== null) {
            $this->snapshots->add((int) $domain['id'], $now, $snapshot);
        }

        return array_replace($domain, $changes);
    }

    private function trackedFields(array $domain, array $result): array
    {
        $fields = ['registrar' => $result['registrar'] ?? $domain['registrar']];
        foreach (['registry_expires_at', 'registrar_expires_at'] as $field) {
            $value = $result[$field] ?? $domain[$field];
            $fields[$field] = $value === null ? null : (int) $value;
        }

        foreach (['statuses', 'nameservers'] as $field) {
            $values = $result[$field] ?? null;
            if (!is_array($values)) {
                $values = json_decode($domain[$field], true, flags: JSON_THROW_ON_ERROR);
            }
            $values = array_unique(array_map('strtolower', array_filter($values, 'is_string')));
            sort($values, SORT_STRING);
            $fields[$field] = $values;
        }

        return $fields;
    }
}
