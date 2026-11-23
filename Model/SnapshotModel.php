<?php

namespace Kanboard\Plugin\Deed\Model;

use PDO;

final class SnapshotModel
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function add(int $domainId, int $observedAt, string $data): void
    {
        $statement = $this->pdo->prepare('INSERT INTO deed_snapshots (domain_id, observed_at, data) VALUES (?, ?, ?)');
        $statement->execute([$domainId, $observedAt, $data]);
    }

    /** Return newest observations first, with decoded snapshot data. */
    public function listForDomain(int $domainId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM deed_snapshots WHERE domain_id = ? ORDER BY observed_at DESC, id DESC'
        );
        $statement->execute([$domainId]);

        $snapshots = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($snapshots as &$snapshot) {
            $snapshot['data'] = json_decode($snapshot['data'], true, flags: JSON_THROW_ON_ERROR);
        }

        return $snapshots;
    }

    public static function diff(array $prev, array $next): array
    {
        $changes = [];
        foreach (array_keys($prev + $next) as $field) {
            $before = $prev[$field] ?? null;
            $after = $next[$field] ?? null;
            if ($before !== $after) {
                $changes[$field] = ['before' => $before, 'after' => $after];
            }
        }

        return $changes;
    }
}
