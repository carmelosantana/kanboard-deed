<?php

namespace Kanboard\Plugin\Deed\Model;

use Closure;
use Kanboard\Model\CommentModel;
use Kanboard\Model\ConfigModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\TaskModel;
use Kanboard\Model\TaskStatusModel;
use Kanboard\Plugin\Deed\Client\RdapClient;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class RenewalService
{
    private const DAY = 86400;
    private const DEFAULT_THRESHOLDS = [30, 14, 7];
    private const EXPIRY_CHANGED = 'expiry_changed';
    private const EXPIRY_CHANGED_COMMENT = 'Expiry changed — registry now shows ';
    private const POSTGRES_LOCK_NAMESPACE = 0x44454544; // DEED
    private const MYSQL_LOCK_KEY = "CONCAT('deed:', LEFT(SHA2(DATABASE(), 256), 40), ':', ?)";

    private readonly string $driver;
    private ?string $lastProblem = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ConfigModel $config,
        private readonly ProjectModel $projects,
        private readonly TaskCreationModel $taskCreation,
        private readonly TaskStatusModel $taskStatus,
        private readonly CommentModel $comments
    ) {
        $this->driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function lastProblem(): ?string
    {
        return $this->lastProblem;
    }

    public function openRenewalFor(int $domainId, ?Closure $canAccessProject = null): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT tasks.id, tasks.title, tasks.project_id FROM deed_renewals
             JOIN tasks ON tasks.id = deed_renewals.task_id
             WHERE deed_renewals.domain_id = ? AND deed_renewals.closed_at IS NULL AND tasks.is_active = ?
             ORDER BY deed_renewals.cycle_expires_at DESC'
        );
        $statement->execute([$domainId, TaskModel::STATUS_OPEN]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $task) {
            if ($canAccessProject === null || $canAccessProject((int) $task['project_id'])) {
                return $task;
            }
        }

        return null;
    }

    public function evaluate(array $domain, int $now): void
    {
        $this->lastProblem = null;
        $expiry = RdapClient::effectiveExpiry($domain['registry_expires_at'], $domain['registrar_expires_at']);
        if ($expiry === null && $domain['status'] !== 'not_found') {
            return;
        }

        // Core event subscribers can commit the shared PDO transaction. Use locks
        // that survive core writes so concurrent evaluations cannot both retry a claim.
        $release = $this->acquireLock((int) $domain['id']);
        try {
            if ($this->isCurrentDomain($domain)) {
                $this->evaluateCycles($domain, $now, $expiry);
            }
        } finally {
            $release();
        }
    }

    private function acquireLock(int $domainId): Closure
    {
        if ($this->driver === 'sqlite') {
            $path = $this->pdo->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'];
            if ($path === '') {
                // Kanboard's in-memory SQLite database is private to this connection.
                return static function (): void {};
            }
            $lock = fopen($path.'.deed-renewals.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                throw new RuntimeException('Could not lock Deed renewals');
            }
            return static function () use ($lock): void {
                fclose($lock);
            };
        }

        if ($this->driver === 'mysql') {
            $statement = $this->pdo->prepare('SELECT GET_LOCK('.self::MYSQL_LOCK_KEY.', 10)');
            $statement->execute([$domainId]);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException('Could not lock Deed renewals');
            }
            $unlock = 'SELECT RELEASE_LOCK('.self::MYSQL_LOCK_KEY.')';
        } else {
            $key = self::POSTGRES_LOCK_NAMESPACE.', ?';
            $statement = $this->pdo->prepare("SELECT CASE WHEN pg_try_advisory_lock($key) THEN 1 ELSE 0 END");
            $deadline = hrtime(true) + 10_000_000_000;
            do {
                $statement->execute([$domainId]);
                if ((int) $statement->fetchColumn() === 1) {
                    return function () use ($key, $domainId): void {
                        $this->pdo->prepare("SELECT pg_advisory_unlock($key)")->execute([$domainId]);
                    };
                }
                $remaining = $deadline - hrtime(true);
                if ($remaining <= 0) {
                    break;
                }
                usleep((int) min(50_000, intdiv($remaining, 1000)));
            } while (hrtime(true) < $deadline);
            throw new RuntimeException('Could not lock Deed renewals');
        }

        return function () use ($unlock, $domainId): void {
            $this->pdo->prepare($unlock)->execute([$domainId]);
        };
    }

    private function isCurrentDomain(array $domain): bool
    {
        $statement = $this->pdo->prepare('SELECT last_checked_at FROM deed_domains WHERE id = ?');
        $statement->execute([$domain['id']]);
        $current = $statement->fetch(PDO::FETCH_ASSOC);

        return $current !== false
            && (string) $current['last_checked_at'] === (string) ($domain['last_checked_at'] ?? null);
    }

    private function evaluateCycles(array $domain, int $now, ?int $expiry): void
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM deed_renewals WHERE domain_id = ? AND (closed_at IS NULL OR cycle_expires_at = ?) ORDER BY id'
        );
        $statement->execute([$domain['id'], $expiry]);
        $current = null;
        $notFound = $domain['status'] === 'not_found';
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $cycle) {
            // Core may have saved a task before a previous run could link it.
            if ($cycle['closed_at'] === null && (int) $cycle['task_id'] === 0) {
                $cycle['task_id'] = $this->recoverTask((int) $cycle['id']);
            }
            if ($notFound) {
                $this->sendNotices($cycle, ['not_found' => "Registry reports {$domain['name']} as not registered."]);
            } elseif ((int) $cycle['cycle_expires_at'] === $expiry) {
                $current = $cycle;
            } else {
                $this->closeCycle($cycle, $expiry, $now);
            }
        }

        if ($notFound) {
            return;
        }

        if ($current !== null && $current['closed_at'] !== null) {
            $current = $this->reopenSupersededCycle($current);
        }

        $thresholds = $this->thresholds();
        $first = array_shift($thresholds);
        $secondsLeft = $expiry - $now;
        if (($current === null || ($current['closed_at'] === null && (int) $current['task_id'] === 0))
            && $secondsLeft <= $first * self::DAY) {
            $current = $this->createCycle($domain, $expiry, $current);
        }

        if ($current !== null) {
            $days = max(0, (int) ceil($secondsLeft / self::DAY));
            $notices = [];
            foreach ($thresholds as $threshold) {
                if ($secondsLeft <= $threshold * self::DAY) {
                    $notices[$threshold] = "Expires in {$days} days.";
                }
            }
            $this->sendNotices($current, $notices);
        }
    }

    private function thresholds(): array
    {
        $thresholds = [];
        foreach (explode(',', $this->config->getOption('deed_thresholds')) as $part) {
            $value = filter_var(trim($part), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($value !== false) {
                $thresholds[$value] = $value;
            }
        }
        rsort($thresholds, SORT_NUMERIC);

        return $thresholds ?: self::DEFAULT_THRESHOLDS;
    }

    private function createCycle(array $domain, int $expiry, ?array $cycle): ?array
    {
        $projectId = (int) $domain['project_id'];
        if (!$this->projects->exists($projectId)) {
            $projectId = (int) $this->config->getOption('deed_default_project_id', 0);
            if (!$this->projects->exists($projectId)) {
                $this->lastProblem = 'no_project';
                return null;
            }
        }

        if ($cycle === null) {
            $cycle = $this->claimCycle((int) $domain['id'], $expiry);
            if ($cycle === null) {
                return null;
            }
        }

        $cycleId = (int) $cycle['id'];
        try {
            $taskId = $this->taskCreation->create([
                'title' => "Renew {$domain['name']} (expires ".gmdate('Y-m-d', $expiry).')',
                'project_id' => $projectId,
                'date_due' => $expiry,
                'owner_id' => (int) $domain['assignee_id'],
                'reference' => $this->taskReference($cycleId),
            ]);
        } catch (Throwable $exception) {
            // Core inserts the task before dispatching events and running hooks.
            $this->recoverTask($cycleId);
            throw $exception;
        }
        if ($taskId <= 0) {
            throw new RuntimeException('Could not create Deed renewal task');
        }

        $this->linkTask($cycleId, $taskId);
        $cycle['task_id'] = $taskId;
        return $cycle;
    }

    private function claimCycle(int $domainId, int $expiry): ?array
    {
        $sql = 'INSERT INTO deed_renewals (domain_id, cycle_expires_at, task_id) VALUES (?, ?, 0)';
        if ($this->driver !== 'mysql') {
            $sql .= ' ON CONFLICT (domain_id, cycle_expires_at) DO NOTHING';
        }
        $statement = $this->pdo->prepare($sql);
        try {
            $statement->execute([$domainId, $expiry]);
        } catch (PDOException $exception) {
            if ($this->driver === 'mysql' && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $exception;
        }
        if ($statement->rowCount() === 0) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT * FROM deed_renewals WHERE domain_id = ? AND cycle_expires_at = ?'
        );
        $statement->execute([$domainId, $expiry]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function taskReference(int $cycleId): string
    {
        return 'deed:renewal:'.$cycleId;
    }

    private function recoverTask(int $cycleId): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM tasks WHERE reference = ? ORDER BY id LIMIT 1');
        $statement->execute([$this->taskReference($cycleId)]);
        $taskId = (int) $statement->fetchColumn();
        if ($taskId > 0) {
            $this->linkTask($cycleId, $taskId);
        }
        return $taskId;
    }

    private function linkTask(int $cycleId, int $taskId): void
    {
        $this->pdo->prepare('UPDATE deed_renewals SET task_id = ? WHERE id = ?')->execute([$taskId, $cycleId]);
    }

    private function closeCycle(array $cycle, int $expiry, int $now): void
    {
        $taskId = (int) $cycle['task_id'];
        if ($this->isOpenTask($taskId)) {
            $superseded = $expiry < (int) $cycle['cycle_expires_at'];
            $prefix = $superseded ? self::EXPIRY_CHANGED_COMMENT : 'Renewed — registry now shows ';
            $this->comment($taskId, $prefix.gmdate('Y-m-d', $expiry).'.');
            if (!$this->taskStatus->close($taskId)) {
                throw new RuntimeException('Could not close Deed renewal task');
            }
            if ($superseded) {
                $sent = json_decode($cycle['thresholds_sent'], true, flags: JSON_THROW_ON_ERROR);
                $sent[] = self::EXPIRY_CHANGED;
                $cycle['thresholds_sent'] = json_encode($sent, JSON_THROW_ON_ERROR);
            }
        }

        $this->pdo->prepare('UPDATE deed_renewals SET closed_at = ?, thresholds_sent = ? WHERE id = ?')
            ->execute([$now, $cycle['thresholds_sent'], $cycle['id']]);
    }

    private function reopenSupersededCycle(array $cycle): array
    {
        $taskId = (int) $cycle['task_id'];
        if (!$this->taskStatus->isClosed($taskId)) {
            return $cycle;
        }

        $sent = json_decode($cycle['thresholds_sent'], true, flags: JSON_THROW_ON_ERROR);
        if (!in_array(self::EXPIRY_CHANGED, $sent, true)) {
            // Earlier versions recorded the closure reason only in the task comment.
            $statement = $this->pdo->prepare(
                'SELECT 1 FROM comments WHERE task_id = ? AND user_id = 0 AND comment LIKE ? LIMIT 1'
            );
            $statement->execute([$taskId, self::EXPIRY_CHANGED_COMMENT.'%']);
            if (!$statement->fetchColumn()) {
                return $cycle;
            }
        }

        // Retire the old recovery reference atomically with resetting the claim.
        // A retry after interrupted creation must only find the replacement task.
        $reference = $this->taskReference((int) $cycle['id']);
        $this->pdo->beginTransaction();
        try {
            $retire = $this->pdo->prepare('UPDATE tasks SET reference = ? WHERE id = ? AND reference = ? AND is_active = ?');
            $retire->execute([$reference.':superseded:'.$taskId, $taskId, $reference, TaskModel::STATUS_CLOSED]);
            if ($retire->rowCount() === 0) {
                $this->pdo->commit();
                return $cycle;
            }
            $this->pdo->prepare('UPDATE deed_renewals SET closed_at = NULL, task_id = 0, thresholds_sent = ? WHERE id = ?')
                ->execute(['[]', $cycle['id']]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return array_replace($cycle, ['closed_at' => null, 'task_id' => 0, 'thresholds_sent' => '[]']);
    }

    private function sendNotices(array $cycle, array $notices): void
    {
        if ($cycle['closed_at'] !== null || $notices === []) {
            return;
        }

        $sent = json_decode($cycle['thresholds_sent'], true, flags: JSON_THROW_ON_ERROR);
        $pending = array_diff_key($notices, array_fill_keys($sent, true));
        $taskId = (int) $cycle['task_id'];
        if ($pending === [] || !$this->isOpenTask($taskId)) {
            return;
        }

        $this->comment($taskId, reset($pending));
        $sent = array_merge($sent, array_keys($pending));

        $statement = $this->pdo->prepare(
            'UPDATE deed_renewals SET thresholds_sent = ? WHERE domain_id = ? AND cycle_expires_at = ?'
        );
        $statement->execute([json_encode($sent, JSON_THROW_ON_ERROR), $cycle['domain_id'], $cycle['cycle_expires_at']]);
    }

    private function isOpenTask(int $taskId): bool
    {
        $statement = $this->pdo->prepare('SELECT is_active FROM tasks WHERE id = ?');
        $statement->execute([$taskId]);

        return (int) $statement->fetchColumn() === TaskModel::STATUS_OPEN;
    }

    private function comment(int $taskId, string $message): void
    {
        if (!$this->comments->create(['task_id' => $taskId, 'user_id' => 0, 'comment' => $message])) {
            throw new RuntimeException('Could not comment on Deed renewal task');
        }
    }
}
