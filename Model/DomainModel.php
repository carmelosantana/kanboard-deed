<?php

namespace Kanboard\Plugin\Deed\Model;

use Closure;
use InvalidArgumentException;
use Kanboard\Plugin\Deed\Client\RdapClient;
use PDO;
use PDOException;
use Throwable;

final class DomainModel
{
    private readonly Closure $clock;

    public function __construct(private readonly PDO $pdo, ?Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    public static function normalise(string $input): string
    {
        $host = trim($input, " \t\r\n\v\f");
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $host)) {
            $host = parse_url($host, PHP_URL_HOST);
            if (!is_string($host)) {
                throw new InvalidArgumentException('invalid_domain');
            }
        }

        if (preg_match('/[^\x00-\x7f]/', $host)) {
            throw new InvalidArgumentException('idn_unsupported');
        }

        $name = strtolower(str_ends_with($host, '.') ? substr($host, 0, -1) : $host);
        if (strlen($name) > 253 || !str_contains($name, '.') || filter_var($name, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('invalid_domain');
        }

        foreach (explode('.', $name) as $label) {
            if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $label)) {
                throw new InvalidArgumentException('invalid_domain');
            }
        }

        return $name;
    }

    public function get(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM deed_domains WHERE id = ?');
        $statement->execute([$id]);

        $domain = $statement->fetch(PDO::FETCH_ASSOC);
        return $domain === false ? null : $domain;
    }

    /** Return the new domain ID, or null if the name is already watched. */
    public function create(string $input, int $createdBy, int $projectId = 0, int $assigneeId = 0): ?int
    {
        return $this->insert(self::normalise($input), $createdBy, $projectId, $assigneeId);
    }

    public function bulkAdd(string $input, int $createdBy, int $projectId = 0, int $assigneeId = 0): array
    {
        $result = ['added' => [], 'existing' => [], 'invalid' => []];
        $seen = [];
        $invalid = [];
        foreach (preg_split('/[,\r\n]+/', $input) as $entry) {
            $entry = trim($entry, " \t\r\n\v\f");
            if ($entry === '') {
                continue;
            }

            try {
                $name = self::normalise($entry);
            } catch (InvalidArgumentException) {
                if (!isset($invalid[$entry])) {
                    $invalid[$entry] = true;
                    $result['invalid'][] = $entry;
                }
                continue;
            }

            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $id = $this->insert($name, $createdBy, $projectId, $assigneeId);
                $result[$id === null ? 'existing' : 'added'][] = $name;
            }
        }

        return $result;
    }

    private function insert(string $name, int $createdBy, int $projectId, int $assigneeId): ?int
    {
        $now = ($this->clock)();
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = "INSERT INTO deed_domains (name, project_id, assignee_id, created_by, created_at, status, next_check_at)
                VALUES (?, ?, ?, ?, ?, 'pending', ?)";
        if (!$mysql) {
            $sql .= ' ON CONFLICT (name) DO NOTHING RETURNING id';
        }

        $statement = $this->pdo->prepare($sql);
        try {
            $statement->execute([$name, $projectId, $assigneeId, $createdBy, $now, $now]);
        } catch (PDOException $exception) {
            if ($mysql && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $exception;
        }

        $id = $mysql ? $this->pdo->lastInsertId() : $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function update(int $id, int $projectId, int $assigneeId): void
    {
        $statement = $this->pdo->prepare('UPDATE deed_domains SET project_id = ?, assignee_id = ? WHERE id = ?');
        $statement->execute([$projectId, $assigneeId, $id]);
    }

    public function remove(int $id): bool
    {
        $this->pdo->beginTransaction();

        try {
            $domain = $this->pdo->prepare('DELETE FROM deed_domains WHERE id = ?');
            $domain->execute([$id]);
            $removed = $domain->rowCount() > 0;

            $this->pdo->prepare('DELETE FROM deed_snapshots WHERE domain_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM deed_renewals WHERE domain_id = ?')->execute([$id]);

            $this->pdo->commit();
            return $removed;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function listAll(): array
    {
        return $this->orderByExpiry($this->pdo->query('SELECT * FROM deed_domains')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function listForProject(int $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM deed_domains WHERE project_id = ?');
        $statement->execute([$projectId]);

        return $this->orderByExpiry($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function orderByExpiry(array $domains): array
    {
        $order = [];
        foreach ($domains as $domain) {
            $expiry = RdapClient::effectiveExpiry($domain['registry_expires_at'], $domain['registrar_expires_at']);
            $order[] = [$expiry === null, $expiry, $domain['name']];
        }
        array_multisort($order, SORT_ASC, $domains);

        return $domains;
    }
}
