<?php

namespace Kanboard\Plugin\Deed\Model;

use Kanboard\Model\CommentModel;
use Kanboard\Model\ConfigModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\TaskModel;
use Kanboard\Model\TaskStatusModel;
use Kanboard\Plugin\Deed\Client\RdapClient;
use PDO;
use RuntimeException;
use Throwable;

final class RenewalService
{
    private const DAY = 86400;
    private const DEFAULT_THRESHOLDS = [30, 14, 7];

    private readonly string $rowLock;
    private ?string $lastProblem = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ConfigModel $config,
        private readonly ProjectModel $projects,
        private readonly TaskCreationModel $taskCreation,
        private readonly TaskStatusModel $taskStatus,
        private readonly CommentModel $comments
    ) {
        $this->rowLock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }

    public function lastProblem(): ?string
    {
        return $this->lastProblem;
    }

    public function evaluate(array $domain, int $now): void
    {
        $this->lastProblem = null;
        $expiry = RdapClient::effectiveExpiry($domain['registry_expires_at'], $domain['registrar_expires_at']);
        if ($expiry === null && $domain['status'] !== 'not_found') {
            return;
        }

        // Serialize evaluations per domain and persist the core task writes with the cycle.
        $this->pdo->beginTransaction();
        try {
            if ($this->lockDomain($domain)) {
                $this->evaluateCycles($domain, $now, $expiry);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function lockDomain(array $domain): bool
    {
        if ($this->rowLock === '') {
            // SQLite starts a deferred transaction, so take the write lock before reading.
            $this->pdo->prepare('UPDATE deed_domains SET id = id WHERE id = ?')->execute([$domain['id']]);
        }

        $statement = $this->pdo->prepare('SELECT last_checked_at FROM deed_domains WHERE id = ?'.$this->rowLock);
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
            if ($notFound) {
                $this->sendNotices($cycle, ['not_found' => "Registry reports {$domain['name']} as not registered."]);
            } elseif ((int) $cycle['cycle_expires_at'] === $expiry) {
                $current = $cycle;
            } elseif ($cycle['cycle_expires_at'] < $expiry) {
                $this->closeCycle($cycle, $expiry, $now);
            }
        }

        if ($notFound) {
            return;
        }

        $thresholds = $this->thresholds();
        $first = array_shift($thresholds);
        $days = (int) ceil(($expiry - $now) / self::DAY);
        if ($current === null && $days <= $first) {
            $current = $this->createCycle($domain, $expiry);
        }

        if ($current !== null) {
            $notices = [];
            foreach ($thresholds as $threshold) {
                if ($days <= $threshold) {
                    $notices[$threshold] = "Expires in {$threshold} days.";
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

    private function createCycle(array $domain, int $expiry): ?array
    {
        $projectId = (int) $domain['project_id'];
        if (!$this->projects->exists($projectId)) {
            $projectId = (int) $this->config->getOption('deed_default_project_id', 0);
            if (!$this->projects->exists($projectId)) {
                $this->lastProblem = 'no_project';
                return null;
            }
        }

        $taskId = $this->taskCreation->create([
            'title' => "Renew {$domain['name']} (expires ".gmdate('Y-m-d', $expiry).')',
            'project_id' => $projectId,
            'date_due' => $expiry,
            'owner_id' => (int) $domain['assignee_id'],
        ]);
        if ($taskId <= 0) {
            throw new RuntimeException('Could not create Deed renewal task');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO deed_renewals (domain_id, cycle_expires_at, task_id) VALUES (?, ?, ?)'
        );
        $statement->execute([$domain['id'], $expiry, $taskId]);

        return [
            'domain_id' => $domain['id'],
            'cycle_expires_at' => $expiry,
            'task_id' => $taskId,
            'closed_at' => null,
            'thresholds_sent' => '[]',
        ];
    }

    private function closeCycle(array $cycle, int $expiry, int $now): void
    {
        $taskId = (int) $cycle['task_id'];
        if ($this->lockOpenTask($taskId)) {
            $this->comment($taskId, 'Renewed — registry now shows '.gmdate('Y-m-d', $expiry).'.');
            if (!$this->taskStatus->close($taskId)) {
                throw new RuntimeException('Could not close Deed renewal task');
            }
        }

        $this->pdo->prepare('UPDATE deed_renewals SET closed_at = ? WHERE id = ?')->execute([$now, $cycle['id']]);
    }

    private function sendNotices(array $cycle, array $notices): void
    {
        if ($cycle['closed_at'] !== null || $notices === []) {
            return;
        }

        $sent = json_decode($cycle['thresholds_sent'], true, flags: JSON_THROW_ON_ERROR);
        $pending = array_diff_key($notices, array_fill_keys($sent, true));
        $taskId = (int) $cycle['task_id'];
        if ($pending === [] || !$this->lockOpenTask($taskId)) {
            return;
        }

        foreach ($pending as $marker => $message) {
            $this->comment($taskId, $message);
            $sent[] = $marker;
        }

        $statement = $this->pdo->prepare(
            'UPDATE deed_renewals SET thresholds_sent = ? WHERE domain_id = ? AND cycle_expires_at = ?'
        );
        $statement->execute([json_encode($sent, JSON_THROW_ON_ERROR), $cycle['domain_id'], $cycle['cycle_expires_at']]);
    }

    private function lockOpenTask(int $taskId): bool
    {
        $statement = $this->pdo->prepare('SELECT is_active FROM tasks WHERE id = ?'.$this->rowLock);
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
