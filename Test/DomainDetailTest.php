<?php

namespace Kanboard\Plugin\Deed\Test;

use Kanboard\Core\Controller\Runner;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Middleware\ApplicationAuthorizationMiddleware;
use Kanboard\Plugin\Deed\Client\HttpFetcher;
use Kanboard\Plugin\Deed\Model\DnsLookup;
use Kanboard\Plugin\Deed\Plugin;
use KanboardTests\units\Base;
use function Kanboard\Plugin\Deed\Schema\version_1;

require_once 'tests/units/Base.php';
require_once __DIR__.'/ReleasesConnection.php';

class DomainDetailTest extends Base
{
    use ReleasesConnection;

    private int $domainId;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        version_1($this->container['db']->getConnection());
        $this->container['route']->enable();
        (new Plugin($this->container))->initialize();
        $this->container['configModel']->save(['deed_last_sweep' => date('Y-m-d')]);
        $fetcher = $this->createMock(HttpFetcher::class);
        $fetcher->expects($this->never())->method('fetch');
        $this->container['deedHttp'] = $fetcher;
        $this->container['deedDnsLookup'] = new DnsLookup(static fn (): array => []);
        $this->domainId = $this->container['deedDomainModel']->create('example.com', 1);
    }

    public function testShowRendersRegistrationSourcesFreshnessRenewalAndHistory(): void
    {
        $registry = 'https://rdap.example.com/domain/example.com';
        $registrar = 'https://registrar.example.com/domain/example.com';
        $success = time() - 4 * 86400;
        $expiry = strtotime('2030-07-31 UTC');
        $pdo = $this->container['db']->getConnection();
        $pdo->prepare(
            'UPDATE deed_domains SET rdap_url = ?, last_success_at = ?, registry_expires_at = ?, statuses = ?, nameservers = ? WHERE id = ?'
        )->execute([$registry, $success, $expiry, '["client transfer prohibited"]', '["ns2.example.com"]', $this->domainId]);
        $this->container['deedCache']->save([
            'cache_key' => 'lookup:domain:'.hash('sha256', 'example.com'), 'url' => $registry,
            'body' => json_encode(['status' => 'ok', 'rdap_url' => $registry, 'related_url' => $registrar]),
            'etag' => null, 'last_modified' => null, 'fetched_at' => $success,
            'expires_at' => $success + 86400, 'http_status' => 200,
        ], null);

        $snapshots = $this->container['deedSnapshotModel'];
        $snapshots->add($this->domainId, $success - 2, '{"registrar":"Unchanged Registrar","nameservers":["ns1.example.com"]}');
        $snapshots->add($this->domainId, $success - 1, '{"registrar":"Unchanged Registrar","nameservers":["ns2.example.com"]}');
        $snapshots->add($this->domainId, $success, '{"registrar":"New <Registrar>","nameservers":["ns2.example.com"]}');

        $project = $this->container['projectModel']->create(['name' => 'Renewals'], 1, true);
        $tasks = $this->container['taskCreationModel'];
        $open = $tasks->create(['title' => 'Open <renewal>', 'project_id' => $project]);
        $closed = $tasks->create(['title' => 'Closed renewal', 'project_id' => $project]);
        $this->container['taskStatusModel']->close($closed);
        $insert = $pdo->prepare('INSERT INTO deed_renewals (domain_id, cycle_expires_at, task_id, thresholds_sent) VALUES (?, ?, ?, ?)');
        $insert->execute([$this->domainId, $expiry, $open, '[]']);
        $insert->execute([$this->domainId, $expiry + 86400, $closed, '[]']);

        [$status, $body] = $this->dispatch('show');
        $this->assertSame(200, $status);
        foreach ([$registry, $registrar] as $source) {
            $this->assertStringContainsString('href="'.$source.'"', $body);
        }
        $this->assertStringContainsString($this->container['helper']->dt->datetime($success), $body);
        $this->assertStringContainsString('deed-stale', $body);
        $this->assertStringContainsString('client transfer prohibited', $body);
        $taskUrl = $this->container['helper']->url->href('TaskViewController', 'show', ['task_id' => $open]);
        $this->assertStringContainsString('href="'.$taskUrl.'"', $body);
        $this->assertStringContainsString('Open &lt;renewal&gt;', $body);
        $this->assertStringNotContainsString('Closed renewal', $body);
        $this->assertStringContainsString('New &lt;Registrar&gt;', $body);
        $this->assertStringContainsString('ns1.example.com', $body);
        $this->assertLessThan(strpos($body, 'ns1.example.com'), strpos($body, 'New &lt;Registrar&gt;'));
        $this->assertSame(1, substr_count($body, 'Unchanged Registrar'));
        $this->assertStringContainsString('/dns/'.$this->domainId, $body);
    }

    public function testDnsReturnsOnlyThePartialAndEscapesRecordValues(): void
    {
        $calls = [];
        $this->container['deedDnsLookup'] = new DnsLookup(static function (string $name) use (&$calls): array {
            $calls[] = $name;
            return [['type' => 'TXT', 'host' => $name, 'ttl' => 300, 'txt' => '<script>unsafe</script>']];
        });

        $this->dispatch('show');
        $this->assertSame([], $calls);
        [$status, $body] = $this->dispatch('dns');
        $this->assertSame(200, $status);
        $this->assertSame(['example.com'], $calls);
        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringContainsString('TXT', $body);
        $this->assertStringContainsString('300', $body);
        $this->assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $body);
        $this->assertStringContainsString('DNS TTL is not registration expiry.', $body);
    }

    public function testEditListsAccessibleProjectsAndValidatesAndSavesTheSelection(): void
    {
        $projects = $this->container['projectModel'];
        $allowed = $projects->create(['name' => 'Accessible Project'], 1, true);
        $denied = $projects->create(['name' => 'Hidden Project']);
        $assignee = $this->container['userModel']->create(['username' => 'renewal-owner']);
        $this->container['projectUserRoleModel']->addUser($allowed, $assignee, Role::PROJECT_MEMBER);

        [$status, $body] = $this->dispatch('edit');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Accessible Project', $body);
        $this->assertStringNotContainsString('Hidden Project', $body);

        $domains = $this->container['deedDomainModel'];
        $this->assertSame(403, $this->dispatch('edit', [
            'project_id' => $allowed, 'assignee_id' => $assignee, 'csrf_token' => 'invalid',
        ])[0]);
        [$status, $body] = $this->dispatch('edit', ['project_id' => $denied, 'assignee_id' => $assignee]);
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Select an accessible project.', $body);
        $this->assertStringContainsString('method="post"', $body);
        $this->assertStringNotContainsString('Hidden Project', $body);
        $this->assertMatchesRegularExpression('/<option value="'.$assignee.'"[^>]*selected[^>]*>renewal-owner<\/option>/', $body);
        $this->assertSame(0, (int) $domains->get($this->domainId)['project_id']);

        [$status, $body] = $this->dispatch('edit', ['project_id' => $allowed, 'assignee_id' => 999999]);
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Select an active assignee.', $body);
        $this->assertStringContainsString('method="post"', $body);
        $this->assertMatchesRegularExpression('/<option value="'.$allowed.'"[^>]*selected[^>]*>Accessible Project<\/option>/', $body);
        $this->assertSame(0, (int) $domains->get($this->domainId)['project_id']);

        [$status] = $this->dispatch('edit', ['project_id' => $allowed, 'assignee_id' => $assignee]);
        $this->assertSame(303, $status);
        $domain = $domains->get($this->domainId);
        $this->assertSame((int) $allowed, (int) $domain['project_id']);
        $this->assertSame((int) $assignee, (int) $domain['assignee_id']);
    }

    public function testEditOffersOnlyProjectsWhereTheManagerMayWriteTasks(): void
    {
        // Deed creates, comments on and closes renewal tasks in the chosen project: a viewer may not.
        $projects = $this->container['projectModel'];
        $viewOnly = $projects->create(['name' => 'View Only Project']);
        $this->container['projectUserRoleModel']->addUser($viewOnly, 1, Role::PROJECT_VIEWER);

        [$status, $body] = $this->dispatch('edit');
        $this->assertSame(200, $status);
        // The header's project switcher lists it (the user may view it); the edit form's select must not.
        $this->assertDoesNotMatchRegularExpression('/<option value="'.$viewOnly.'"[^>]*>View Only Project<\/option>/', $body);

        [$status, $body] = $this->dispatch('edit', ['project_id' => $viewOnly, 'assignee_id' => 0]);
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Select an accessible project.', $body);
        $this->assertSame(0, (int) $this->container['deedDomainModel']->get($this->domainId)['project_id']);
    }

    public function testEditRefusesAnAssigneeOutsideTheChosenProject(): void
    {
        // A renewal task assigned to a non-member would show the private project on their dashboard.
        $project = $this->container['projectModel']->create(['name' => 'Accessible Project'], 1, true);
        $outsider = $this->container['userModel']->create(['username' => 'outsider']);

        [$status, $body] = $this->dispatch('edit', ['project_id' => $project, 'assignee_id' => $outsider]);
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Select an assignee who is a member of that project.', $body);
        $this->assertSame(0, (int) $this->container['deedDomainModel']->get($this->domainId)['assignee_id']);

        $this->assertSame(303, $this->dispatch('edit', ['project_id' => $project, 'assignee_id' => 0])[0]);
    }

    public function testManagerCanPreserveAnExistingProjectTheyCanStillWriteTo(): void
    {
        $project = $this->container['projectModel']->create(['name' => 'Hidden Project']);
        $domains = $this->container['deedDomainModel'];
        $domains->update($this->domainId, $project, 0);
        $this->container['projectUserRoleModel']->addUser($project, 1, Role::PROJECT_MANAGER);

        [$status, $body] = $this->dispatch('edit');
        $this->assertSame(200, $status);
        $this->assertMatchesRegularExpression('/<option value="'.$project.'"[^>]*selected[^>]*>Hidden Project<\/option>/', $body);

        $this->assertSame(303, $this->dispatch('edit', ['project_id' => $project, 'assignee_id' => 1])[0]);
        $this->assertSame(303, $this->dispatch('edit', ['project_id' => $project, 'assignee_id' => 0])[0]);
        $this->assertSame((int) $project, (int) $domains->get($this->domainId)['project_id']);
    }

    /** @dataProvider lostWriteAccess */
    public function testKeepIsRefusedWhenTheUserCannotWriteToTheLinkedProject(?string $role): void
    {
        $project = $this->container['projectModel']->create(['name' => 'Linked Project']);
        $domains = $this->container['deedDomainModel'];
        $domains->update($this->domainId, $project, 0);
        if ($role !== null) {
            $this->container['projectUserRoleModel']->addUser($project, 1, $role);
        }

        [$status, $body] = $this->dispatch('edit', ['project_id' => 'keep', 'assignee_id' => 0]);
        $this->assertSame(400, $status);
        $this->assertStringContainsString('Select an accessible project.', $body);
        $this->assertSame((int) $project, (int) $domains->get($this->domainId)['project_id']);
    }

    public static function lostWriteAccess(): iterable
    {
        yield 'viewer' => [Role::PROJECT_VIEWER];
        yield 'removed member' => [null];
    }

    public function testRefreshIsRefusedWhenTheUserCannotWriteToTheLinkedProject(): void
    {
        $project = $this->container['projectModel']->create(['name' => 'Linked Project']);
        $this->container['projectUserRoleModel']->addUser($project, 1, Role::PROJECT_VIEWER);
        $this->container['deedDomainModel']->update($this->domainId, $project, 0);
        $this->container['db']->getConnection()->prepare('UPDATE deed_domains SET registry_expires_at = ? WHERE id = ?')
            ->execute([time() + 5 * 86400, $this->domainId]);

        // The fetcher mock in setUp fails the test if a lookup runs.
        $this->assertSame(403, $this->dispatch('refresh', ['domain_id' => (string) $this->domainId])[0]);
        $this->assertSame(0, (int) $this->container['db']->table('tasks')->eq('project_id', $project)->count());
        $this->assertSame(0, (int) $this->container['db']->table('comments')->count());
    }

    public static function earlierCycleAccess(): iterable
    {
        yield 'viewer of the earlier project' => [Role::PROJECT_VIEWER, 403];
        yield 'not a member of the earlier project' => [null, 403];
        yield 'member of both projects' => [Role::PROJECT_MEMBER, 303];
    }

    /** @dataProvider earlierCycleAccess */
    public function testRefreshNeedsWriteAccessToProjectsHoldingOpenRenewalTasks(?string $roleInEarlier, int $expected): void
    {
        $projects = $this->container['projectModel'];
        $earlier = $projects->create(['name' => 'Earlier Project']);
        $current = $projects->create(['name' => 'Current Project']);
        $roles = $this->container['projectUserRoleModel'];
        $roles->addUser($current, 1, Role::PROJECT_MEMBER);
        if ($roleInEarlier !== null) {
            $roles->addUser($earlier, 1, $roleInEarlier);
        }
        $taskId = $this->container['taskCreationModel']->create(['title' => 'Renew example.com', 'project_id' => $earlier]);
        $pdo = $this->container['db']->getConnection();
        $pdo->prepare('INSERT INTO deed_renewals (domain_id, cycle_expires_at, task_id, thresholds_sent) VALUES (?, ?, ?, ?)')
            ->execute([$this->domainId, strtotime('2030-01-01 UTC'), $taskId, '[]']);
        $this->container['deedDomainModel']->update($this->domainId, $current, 0);
        $pdo->prepare('UPDATE deed_domains SET registry_expires_at = ? WHERE id = ?')
            ->execute([time() + 400 * 86400, $this->domainId]);
        if ($expected === 303) {
            // The refresh proceeds; a failing registry is enough to show it was not refused.
            $fetcher = $this->createMock(HttpFetcher::class);
            $fetcher->method('fetch')->willReturn(new \Kanboard\Plugin\Deed\Client\HttpResponse(500, ''));
            $this->container['deedHttp'] = $fetcher;
        }

        // The fetcher mock in setUp fails the test if a refused refresh runs a lookup.
        $this->assertSame($expected, $this->dispatch('refresh', ['domain_id' => (string) $this->domainId])[0]);
        if ($expected === 403) {
            $this->assertSame(0, (int) $this->container['db']->table('comments')->count());
            $this->assertSame(1, (int) $this->container['db']->table('tasks')->eq('id', $taskId)->eq('is_active', 1)->count());
        }
    }

    public function testCustomProjectRolesAreRefusedForSelectionKeepAndRefresh(): void
    {
        // A custom role may forbid creating or closing tasks; Deed writes through models, so only built-in write roles pass.
        $projects = $this->container['projectModel'];
        $custom = $projects->create(['name' => 'Custom Role Project']);
        $role = $this->container['projectRoleModel']->create($custom, 'Read Mostly');
        $this->assertNotFalse($role);
        $this->container['projectUserRoleModel']->addUser($custom, 1, 'Read Mostly');
        $member = $projects->create(['name' => 'Plain Member Project']);
        $this->container['projectUserRoleModel']->addUser($member, 1, Role::PROJECT_MEMBER);

        [$status, $body] = $this->dispatch('edit');
        $this->assertSame(200, $status);
        $this->assertDoesNotMatchRegularExpression('/<option value="'.$custom.'"[^>]*>Custom Role Project<\/option>/', $body);
        $this->assertMatchesRegularExpression('/<option value="'.$member.'"[^>]*>Plain Member Project<\/option>/', $body);

        $this->assertSame(400, $this->dispatch('edit', ['project_id' => $custom, 'assignee_id' => 0])[0]);
        $this->assertSame(0, (int) $this->container['deedDomainModel']->get($this->domainId)['project_id']);
        $this->assertSame(303, $this->dispatch('edit', ['project_id' => $member, 'assignee_id' => 0])[0]);

        $this->container['deedDomainModel']->update($this->domainId, $custom, 0);
        $this->assertSame(400, $this->dispatch('edit', ['project_id' => 'keep', 'assignee_id' => 0])[0]);
        $this->assertSame((int) $custom, (int) $this->container['deedDomainModel']->get($this->domainId)['project_id']);

        $this->container['db']->getConnection()->prepare('UPDATE deed_domains SET registry_expires_at = ? WHERE id = ?')
            ->execute([time() + 5 * 86400, $this->domainId]);
        $this->assertSame(403, $this->dispatch('refresh', ['domain_id' => (string) $this->domainId])[0]);
        $this->assertSame(0, (int) $this->container['db']->table('tasks')->eq('project_id', $custom)->count());
    }

    public function testRemovalRequiresConfirmationAndCsrfBeforeDeleting(): void
    {
        [$status, $body] = $this->dispatch('remove');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('method="post"', $body);
        $this->assertStringContainsString('Remove example.com', $body);
        $domains = $this->container['deedDomainModel'];
        $this->assertNotNull($domains->get($this->domainId));

        $this->assertSame(403, $this->dispatch('remove', ['csrf_token' => 'invalid'])[0]);
        $this->assertNotNull($domains->get($this->domainId));
        $this->assertSame(303, $this->dispatch('remove', [])[0]);
        $this->assertNull($domains->get($this->domainId));
    }

    public function testRoutesRequireWatchlistPermissionAndAnExistingDomain(): void
    {
        foreach (['show', 'dns', 'edit', 'remove'] as $action) {
            $this->assertSame(403, $this->dispatch($action, role: Role::APP_USER)[0]);
            $this->assertSame(404, $this->dispatch($action, id: 'missing')[0]);
            $this->assertSame(404, $this->dispatch($action, id: '9999')[0]);
        }
    }

    private function dispatch(string $action, ?array $post = null, string $role = Role::APP_MANAGER, ?string $id = null): array
    {
        $user = $this->container['userModel']->getById(1);
        $user['role'] = $role;
        $this->container['userSession']->initialize($user);
        $this->container['memoryCache']->flush();
        if ($post !== null && !array_key_exists('csrf_token', $post)) {
            $post['csrf_token'] = $this->container['token']->getCSRFToken();
        }
        $this->container['request'] = new Request($this->container, [
            'REQUEST_URI' => '/deed/domains/'.$action.($action === 'refresh' ? '' : '/'.($id ?? $this->domainId)),
            'REQUEST_METHOD' => $post === null ? 'GET' : 'POST',
            'SCRIPT_NAME' => '/index.php',
        ], [], $post ?? []);
        $response = new class($this->container) extends Response {
            public int $code = 200;
            public string $body = '';

            public function withStatusCode($statusCode)
            {
                $this->code = $statusCode;
                return parent::withStatusCode($statusCode);
            }

            public function withBody($body)
            {
                $this->body = $body;
                return parent::withBody($body);
            }

            public function send()
            {
            }
        };
        $this->container['response'] = $response;
        $router = $this->container['router'];
        $router->dispatch();
        $this->assertSame('Deed', $router->getPlugin());

        (new class($this->container) extends Runner {
            protected function executeMiddleware()
            {
                (new ApplicationAuthorizationMiddleware($this->container))->execute();
            }
        })->execute();

        return [$response->code, $response->body];
    }
}
