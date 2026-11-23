<?php

namespace Kanboard\Plugin\Deed\Test;

use Kanboard\Plugin\Deed\Model\RenewalService;
use Kanboard\Plugin\Deed\Plugin;
use Kanboard\Subscriber\ProjectDailySummarySubscriber;
use KanboardTests\units\Base;
use PDO;
use RuntimeException;
use function Kanboard\Plugin\Deed\Schema\version_1;

require_once 'tests/units/Base.php';

class RenewalServiceTest extends Base
{
    private const DAY = 86400;

    private PDO $pdo;
    private RenewalService $renewals;
    private int $expiry;
    private int $project;
    private int $assignee;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        $this->pdo = $this->container['db']->getConnection();
        version_1($this->pdo);
        (new Plugin($this->container))->initialize();
        $this->renewals = $this->container['deedRenewalService'];
        $this->expiry = strtotime('2027-05-15 14:15:00 UTC');
        $this->project = $this->container['projectModel']->create(['name' => 'Renewals']);
        $this->assignee = $this->container['userModel']->create(['username' => 'renewal-owner']);
    }

    public function testCreatesAtFirstThresholdUsingEarlierExpiryAndThenCommentsOncePerThreshold(): void
    {
        $domain = $this->watch($this->project);
        $fallback = $this->container['projectModel']->create(['name' => 'Fallback']);
        $this->container['configModel']->save(['deed_default_project_id' => (string) $fallback]);

        $this->evaluate($domain, 31);
        $this->assertSame(0, $this->taskCount());
        $this->assertSame([], $this->cycles());

        $this->evaluate($domain, 30);
        $this->evaluate($domain, 30);
        $this->assertSame(1, $this->taskCount());
        $cycles = $this->cycles();
        $this->assertCount(1, $cycles);
        $this->assertSame((int) $domain['id'], (int) $cycles[0]['domain_id']);
        $this->assertSame($this->expiry, (int) $cycles[0]['cycle_expires_at']);

        $taskId = (int) $cycles[0]['task_id'];
        $task = $this->container['taskFinderModel']->getById($taskId);
        $this->assertSame('Renew example.com (expires 2027-05-15)', $task['title']);
        $this->assertSame($this->expiry, (int) $task['date_due']);
        $this->assertSame($this->project, (int) $task['project_id']);
        $this->assertSame($this->assignee, (int) $task['owner_id']);
        $this->assertSame([], $this->comments($taskId));

        $this->evaluate($domain, 15);
        $this->assertSame([], $this->comments($taskId));
        $this->evaluate($domain, 14);
        $this->evaluate($domain, 14);
        $this->assertSame(['Expires in 14 days.'], $this->comments($taskId));
        $this->evaluate(array_replace($domain, ['status' => 'error']), 7);
        $this->evaluate($domain, 7);
        $this->assertSame(['Expires in 14 days.', 'Expires in 7 days.'], $this->comments($taskId));
        $this->assertSame([14, 7], json_decode($this->cycles()[0]['thresholds_sent'], true));
        $this->assertSame(1, $this->taskCount());
    }

    public function testReminderRoundsUpAtTheSweepHour(): void
    {
        $domain = $this->watch($this->project);
        $this->evaluate($domain, 30);
        $taskId = (int) $this->cycles()[0]['task_id'];
        $now = $this->expiry - 14 * self::DAY + 3600;
        $this->renewals->evaluate($domain, $now);
        $this->renewals->evaluate($domain, $now);

        $this->assertSame(['Expires in 14 days.'], $this->comments($taskId));
    }

    public function testFindsTheNewestOpenRenewalMatchingTheProjectFilter(): void
    {
        $domainId = (int) $this->watch($this->project)['id'];
        $this->assertNull($this->renewals->openRenewalFor($domainId));

        $tasks = $this->container['taskCreationModel'];
        $otherProject = $this->container['projectModel']->create(['name' => 'Other Project']);
        $older = $tasks->create(['title' => 'Older renewal', 'project_id' => $this->project]);
        $newer = $tasks->create(['title' => 'Newer renewal', 'project_id' => $otherProject]);
        $closed = $tasks->create(['title' => 'Closed renewal', 'project_id' => $this->project]);
        $superseded = $tasks->create(['title' => 'Superseded renewal', 'project_id' => $this->project]);
        $this->container['taskStatusModel']->close($closed);
        $insert = $this->pdo->prepare(
            'INSERT INTO deed_renewals (domain_id, cycle_expires_at, task_id, thresholds_sent, closed_at) VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([$domainId, $this->expiry, $older, '[]', null]);
        $insert->execute([$domainId, $this->expiry + self::DAY, $newer, '[]', null]);
        $insert->execute([$domainId, $this->expiry + 2 * self::DAY, $closed, '[]', null]);
        $insert->execute([$domainId, $this->expiry + 3 * self::DAY, $superseded, '[]', $this->expiry]);

        $this->assertEquals($newer, $this->renewals->openRenewalFor($domainId)['id']);
        $visible = $this->renewals->openRenewalFor($domainId, fn (int $id): bool => $id === $this->project);
        $this->assertEquals($older, $visible['id']);
        $this->assertNull($this->renewals->openRenewalFor($domainId, static fn (): bool => false));
    }

    public function testLaterExpiryClosesOldCycleAndWaitsForNewThreshold(): void
    {
        $domain = $this->watch($this->project);
        $this->evaluate($domain, 30);
        $taskId = (int) $this->cycles()[0]['task_id'];
        $renewed = strtotime('2028-05-15 14:15:00 UTC');
        $domain['registrar_expires_at'] = $renewed;
        $domain['registry_expires_at'] = $renewed + self::DAY;
        $now = $this->expiry - 20 * self::DAY;

        $this->renewals->evaluate($domain, $now);
        $this->renewals->evaluate($domain, $now);
        $this->assertSame(['Renewed — registry now shows 2028-05-15.'], $this->comments($taskId));
        $this->assertTrue($this->container['taskStatusModel']->isClosed($taskId));
        $this->assertSame($now, (int) $this->cycles()[0]['closed_at']);
        $this->assertSame(1, $this->taskCount());

        $this->renewals->evaluate($domain, $renewed - 31 * self::DAY);
        $this->assertSame(1, $this->taskCount());
        $this->renewals->evaluate($domain, $renewed - 30 * self::DAY);
        $this->renewals->evaluate($domain, $renewed - 30 * self::DAY);

        $this->assertSame(2, $this->taskCount());
        $cycles = $this->cycles();
        $this->assertCount(2, $cycles);
        $this->assertSame($renewed, (int) $cycles[1]['cycle_expires_at']);
        $this->assertNull($cycles[1]['closed_at']);
        $this->assertTrue($this->container['taskStatusModel']->isOpen((int) $cycles[1]['task_id']));
    }

    public function testRenewalLifecycleWithSynchronousProjectMetrics(): void
    {
        $this->dispatcher->addSubscriber(new ProjectDailySummarySubscriber($this->container));
        $domain = $this->watch($this->project);
        $this->evaluate($domain, 30);
        $this->evaluate($domain, 30);
        $taskId = (int) $this->cycles()[0]['task_id'];
        $this->evaluate($domain, 14);
        $this->evaluate($domain, 7);

        $domain['registrar_expires_at'] += 365 * self::DAY;
        $domain['registry_expires_at'] += 365 * self::DAY;
        $this->evaluate($domain, 6);
        $this->evaluate($domain, 6);

        $this->assertSame(1, $this->taskCount());
        $this->assertCount(1, $this->cycles());
        $this->assertTrue($this->container['taskStatusModel']->isClosed($taskId));
        $this->assertSame($this->expiry - 6 * self::DAY, (int) $this->cycles()[0]['closed_at']);
        $this->assertSame([
            'Expires in 14 days.',
            'Expires in 7 days.',
            'Renewed — registry now shows 2028-05-14.',
        ], $this->comments($taskId));
    }

    public function testEarlierExpirySupersedesPreviousCycle(): void
    {
        $domain = $this->watch($this->project);
        $this->evaluate($domain, 30);
        $taskId = (int) $this->cycles()[0]['task_id'];
        $domain['registry_expires_at'] = $this->expiry - self::DAY;
        $this->evaluate($domain, 30);
        $this->evaluate($domain, 30);

        $cycles = $this->cycles();
        $this->assertCount(2, $cycles);
        $this->assertSame(2, $this->taskCount());
        $this->assertSame(['Expiry changed — registry now shows 2027-05-14.'], $this->comments($taskId));
        $this->assertSame($this->expiry - 30 * self::DAY, (int) $cycles[0]['closed_at']);
        $this->assertTrue($this->container['taskStatusModel']->isClosed($taskId));
        $this->assertNull($cycles[1]['closed_at']);
        $this->assertTrue($this->container['taskStatusModel']->isOpen((int) $cycles[1]['task_id']));
    }

    public function testLateCreationAndMissedSweepsCoalesceThresholds(): void
    {
        $domain = $this->watch($this->project);
        $this->evaluate($domain, 5);
        $this->evaluate($domain, 4);
        $taskId = (int) $this->cycles()[0]['task_id'];
        $this->assertSame(['Expires in 5 days.'], $this->comments($taskId));
        $this->assertSame([14, 7], json_decode($this->cycles()[0]['thresholds_sent'], true));

        $domain['registrar_expires_at'] += 365 * self::DAY;
        $domain['registry_expires_at'] += 365 * self::DAY;
        $expiry = $domain['registrar_expires_at'];
        $this->renewals->evaluate($domain, $expiry - 30 * self::DAY);
        $taskId = (int) $this->cycles()[1]['task_id'];
        $this->renewals->evaluate($domain, $expiry - 5 * self::DAY - 1);
        $this->renewals->evaluate($domain, $expiry - 5 * self::DAY);
        $this->assertSame(['Expires in 6 days.'], $this->comments($taskId));
        $this->assertSame([14, 7], json_decode($this->cycles()[1]['thresholds_sent'], true));
    }

    public function testReturningExpiryReusesCycleAndCreatesOneOpenTask(): void
    {
        $domain = $this->watch($this->project);
        $earlier = array_replace($domain, ['registry_expires_at' => $this->expiry - self::DAY]);
        $this->evaluate($domain, 14);
        $original = $this->cycles()[0];
        $this->evaluate($earlier, 14);
        $earlierTaskId = (int) $this->cycles()[1]['task_id'];

        $this->evaluate($domain, 14);
        $this->evaluate($domain, 14);

        $cycles = $this->cycles();
        $this->assertCount(2, $cycles);
        $this->assertSame($original['id'], $cycles[0]['id']);
        $this->assertNull($cycles[0]['closed_at']);
        $replacementId = (int) $cycles[0]['task_id'];
        $this->assertNotEquals($original['task_id'], $replacementId);
        $this->assertTrue($this->container['taskStatusModel']->isOpen($replacementId));
        $this->assertTrue($this->container['taskStatusModel']->isClosed((int) $original['task_id']));
        $this->assertTrue($this->container['taskStatusModel']->isClosed($earlierTaskId));
        $this->assertSame(['Expires in 14 days.'], $this->comments($replacementId));
        $this->assertSame(3, $this->taskCount());

        $this->evaluate($earlier, 13);
        $this->assertSame(3, $this->taskCount());
        $this->assertSame($earlierTaskId, (int) $this->cycles()[1]['task_id']);
        $this->assertTrue($this->container['taskStatusModel']->isClosed($earlierTaskId));
    }

    public function testHumanClosedTaskStaysClosedAndReceivesNoComments(): void
    {
        $domain = $this->watch($this->project);
        $this->evaluate($domain, 30);
        $taskId = (int) $this->cycles()[0]['task_id'];
        $this->assertTrue($this->container['taskStatusModel']->close($taskId));

        $this->evaluate($domain, 14);
        $domain['status'] = 'not_found';
        $this->evaluate($domain, 10);
        $domain['status'] = 'ok';
        $domain['registrar_expires_at'] += 365 * self::DAY;
        $domain['registry_expires_at'] += 365 * self::DAY;
        $this->evaluate($domain, 7);

        $this->assertSame(1, $this->taskCount());
        $this->assertTrue($this->container['taskStatusModel']->isClosed($taskId));
        $this->assertSame([], $this->comments($taskId));
    }

    public function testDeletedTaskIsNeverRecreatedForTheSameCycle(): void
    {
        $domain = $this->watch($this->project);
        $this->evaluate($domain, 30);
        $taskId = (int) $this->cycles()[0]['task_id'];

        $this->assertTrue($this->container['taskModel']->remove($taskId));
        $this->evaluate($domain, 30);
        $this->evaluate($domain, 14);

        $this->assertSame(0, $this->taskCount());
        $this->assertCount(1, $this->cycles());
        $this->assertSame($taskId, (int) $this->cycles()[0]['task_id']);
    }

    /** @dataProvider terminalTaskActions */
    public function testReturningExpiryDoesNotReplaceAHumanClosedOrDeletedTask(string $action): void
    {
        $domain = $this->watch($this->project);
        $this->evaluate($domain, 30);
        $taskId = (int) $this->cycles()[0]['task_id'];
        if ($action === 'close') {
            $this->assertTrue($this->container['taskStatusModel']->close($taskId));
        } else {
            // Keep SQLite from reusing the deleted task's ID for another task.
            $this->container['taskCreationModel']->create([
                'title' => 'Unrelated task',
                'project_id' => $this->project,
            ]);
            $this->assertTrue($this->container['taskModel']->remove($taskId));
        }

        $this->evaluate(array_replace($domain, ['registry_expires_at' => $this->expiry - self::DAY]), 29);
        $count = $this->taskCount();
        $this->evaluate($domain, 28);

        $cycle = $this->cycles()[0];
        $this->assertNotNull($cycle['closed_at']);
        $this->assertSame($taskId, (int) $cycle['task_id']);
        $this->assertSame($count, $this->taskCount());
        $this->assertSame([], $this->comments($taskId));
    }

    public static function terminalTaskActions(): array
    {
        return [['close'], ['delete']];
    }

    public function testNotFoundCommentsOnceOnAnExistingOpenTask(): void
    {
        $domain = $this->watch($this->project);
        $missing = array_replace($domain, ['status' => 'not_found']);
        $this->evaluate($missing, 30);
        $this->assertSame(0, $this->taskCount());

        $this->evaluate($domain, 30);
        $taskId = (int) $this->cycles()[0]['task_id'];
        $this->evaluate($missing, 29);
        $this->evaluate($missing, 28);

        $this->assertSame(['Registry reports example.com as not registered.'], $this->comments($taskId));
        $this->assertTrue($this->container['taskStatusModel']->isOpen($taskId));
    }

    public function testMissingProjectIsReportedAndDefaultProjectAllowsRetry(): void
    {
        $domain = $this->watch();
        $this->evaluate($domain, 30);

        $this->assertSame('no_project', $this->renewals->lastProblem());
        $this->assertSame(0, $this->taskCount());
        $this->assertSame([], $this->cycles());

        $this->container['configModel']->save(['deed_default_project_id' => (string) $this->project]);
        $this->evaluate($domain, 30);

        $this->assertNull($this->renewals->lastProblem());
        $task = $this->container['taskFinderModel']->getById($this->cycles()[0]['task_id']);
        $this->assertSame($this->project, (int) $task['project_id']);
    }

    public function testConfiguredThresholdsAndFallbackAfterProjectDeletion(): void
    {
        $deleted = $this->container['projectModel']->create(['name' => 'Deleted project']);
        $domain = $this->watch($deleted);
        $this->assertTrue($this->container['projectModel']->remove($deleted));
        $this->container['configModel']->save([
            'deed_default_project_id' => (string) $this->project,
            'deed_thresholds' => '60,7',
        ]);

        $this->evaluate($domain, 61);
        $this->assertSame(0, $this->taskCount());
        $this->evaluate($domain, 60);
        $this->assertSame(1, $this->taskCount());
        $taskId = (int) $this->cycles()[0]['task_id'];
        $task = $this->container['taskFinderModel']->getById($taskId);
        $this->assertSame($this->project, (int) $task['project_id']);

        $this->evaluate($domain, 14);
        $this->assertSame([], $this->comments($taskId));
        $this->evaluate($domain, 7);
        $this->assertSame(['Expires in 7 days.'], $this->comments($taskId));
    }

    public function testFailedTaskCreationLeavesClaimAndCanBeRetried(): void
    {
        $domain = $this->watch($this->project);
        $fail = true;
        $this->container['hook']->on('model:task:creation:prepare', static function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('Task hook failed before saving');
            }
        });

        try {
            $this->evaluate($domain, 30);
            $this->fail('Expected the task hook to interrupt evaluation');
        } catch (RuntimeException $exception) {
            $this->assertSame('Task hook failed before saving', $exception->getMessage());
        }

        $this->assertSame(0, $this->taskCount());
        $cycles = $this->cycles();
        $this->assertCount(1, $cycles);
        $this->assertSame(0, (int) $cycles[0]['task_id']);

        $fail = false;
        $this->evaluate($domain, 30);
        $this->evaluate($domain, 30);
        $this->assertSame(1, $this->taskCount());
        $this->assertSame($cycles[0]['id'], $this->cycles()[0]['id']);
        $this->assertGreaterThan(0, (int) $this->cycles()[0]['task_id']);
    }

    public function testPersistedTaskSurvivesCreationHookFailureWithoutDuplication(): void
    {
        $domain = $this->watch($this->project);
        $fail = true;
        $this->container['hook']->on('model:task:creation:aftersave', static function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('Task hook failed after saving');
            }
        });

        try {
            $this->evaluate($domain, 30);
            $this->fail('Expected the task hook to interrupt evaluation');
        } catch (RuntimeException $exception) {
            $this->assertSame('Task hook failed after saving', $exception->getMessage());
        }

        $this->assertSame(1, $this->taskCount());
        $this->assertCount(1, $this->cycles());
        $taskId = (int) $this->pdo->query('SELECT id FROM tasks')->fetchColumn();

        $fail = false;
        $this->evaluate($domain, 30);
        $this->evaluate($domain, 30);
        $this->assertSame(1, $this->taskCount());
        $this->assertCount(1, $this->cycles());
        $this->assertSame($taskId, (int) $this->cycles()[0]['task_id']);
    }

    public function testEarlierExpiryClosesTaskWhoseCycleLinkWasInterrupted(): void
    {
        $domain = $this->watch($this->project);
        $this->evaluate($domain, 30);
        $taskId = (int) $this->cycles()[0]['task_id'];
        $this->pdo->exec('UPDATE deed_renewals SET task_id = 0');

        $domain['registrar_expires_at'] -= self::DAY;
        $this->evaluate($domain, 30);

        $cycles = $this->cycles();
        $this->assertCount(2, $cycles);
        $this->assertSame($taskId, (int) $cycles[0]['task_id']);
        $this->assertTrue($this->container['taskStatusModel']->isClosed($taskId));
        $this->assertTrue($this->container['taskStatusModel']->isOpen((int) $cycles[1]['task_id']));
    }

    private function watch(int $project = 0): array
    {
        $domains = $this->container['deedDomainModel'];
        $id = $domains->create('example.com', 1, $project, $this->assignee);

        return array_replace($domains->get($id), [
            'status' => 'ok',
            'registry_expires_at' => $this->expiry + self::DAY,
            'registrar_expires_at' => $this->expiry,
        ]);
    }

    private function evaluate(array $domain, int $days): void
    {
        $this->renewals->evaluate($domain, $this->expiry - $days * self::DAY);
    }

    private function taskCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
    }

    private function cycles(): array
    {
        return $this->pdo->query('SELECT * FROM deed_renewals ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function comments(int $taskId): array
    {
        return array_column($this->container['commentModel']->getAll($taskId), 'comment');
    }
}
