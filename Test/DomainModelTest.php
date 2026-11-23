<?php

namespace Kanboard\Plugin\Deed\Test;

use InvalidArgumentException;
use Kanboard\Plugin\Deed\Model\DomainModel;
use Kanboard\Plugin\Deed\Plugin;
use KanboardTests\units\Base;
use PDO;
use function Kanboard\Plugin\Deed\Schema\version_1;

require_once 'tests/units/Base.php';
require_once __DIR__.'/ReleasesConnection.php';

class DomainModelTest extends Base
{
    use ReleasesConnection;

    private const NOW = 1700000000;

    private PDO $pdo;
    private DomainModel $domains;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        $this->pdo = $this->container['db']->getConnection();
        version_1($this->pdo);
        $this->domains = new DomainModel($this->pdo, static fn () => self::NOW);
    }

    public function testPluginRegistersDomainModel(): void
    {
        (new Plugin($this->container))->initialize();

        $this->assertInstanceOf(DomainModel::class, $this->container['deedDomainModel']);
    }

    public function testNormalisesNamesAndUrlHosts(): void
    {
        $longLabel = str_repeat('a', 63);
        $maximum = "$longLabel.$longLabel.$longLabel.".str_repeat('b', 61);

        $this->assertSame('www.example.com', DomainModel::normalise(' WWW.Example.COM. '));
        $this->assertSame('example.com', DomainModel::normalise('https://Example.COM:8443/café?q=x'));
        $this->assertSame('xn--bcher-kva.example', DomainModel::normalise('xn--bcher-kva.example'));
        $this->assertSame($maximum, DomainModel::normalise($maximum));
    }

    /** @dataProvider invalidNames */
    public function testRejectsInvalidNames(string $input, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        DomainModel::normalise($input);
    }

    public static function invalidNames(): array
    {
        $longLabel = str_repeat('a', 63);

        return [
            ['bücher.example', 'idn_unsupported'],
            ['https://bücher.example/x', 'idn_unsupported'],
            ['example', 'invalid_domain'],
            ['bad_name.com', 'invalid_domain'],
            ['-bad.example', 'invalid_domain'],
            ['bad-.example', 'invalid_domain'],
            ['bad..example', 'invalid_domain'],
            ['example.com..', 'invalid_domain'],
            ['example.com/path', 'invalid_domain'],
            ['https://', 'invalid_domain'],
            ['192.0.2.1', 'invalid_domain'],
            ['2001:db8::1', 'invalid_domain'],
            [str_repeat('a', 64).'.com', 'invalid_domain'],
            ["$longLabel.$longLabel.$longLabel.".str_repeat('b', 62), 'invalid_domain'],
        ];
    }

    public function testCreateStoresPendingDomainDueImmediately(): void
    {
        $id = $this->domains->create(' Example.COM. ', 3, 7, 11);
        $domain = $this->domains->get($id);

        $this->assertSame('example.com', $domain['name']);
        $this->assertSame('pending', $domain['status']);
        $this->assertSame(self::NOW, (int) $domain['created_at']);
        $this->assertSame(self::NOW, (int) $domain['next_check_at']);
        $this->assertSame(3, (int) $domain['created_by']);
        $this->assertSame(7, (int) $domain['project_id']);
        $this->assertSame(11, (int) $domain['assignee_id']);
        $this->assertNull($this->domains->create('https://example.com', 4));
        $this->assertSame($domain, $this->domains->get($id));
    }

    public function testBulkAddReportsEachNameOnce(): void
    {
        $this->assertSame([
            'added' => ['a.com', 'b.org'],
            'existing' => [],
            'invalid' => ['bad_name'],
        ], $this->domains->bulkAdd("a.com b.org,\tbad_name\r\na.com", 3));

        $this->assertSame([
            'added' => ['c.net'],
            'existing' => ['a.com'],
            'invalid' => ['bad_name'],
        ], $this->domains->bulkAdd(" A.COM. ,\r\nhttps://a.com/path,bad_name,bad_name,,C.NET", 4, 7, 11));

        $this->assertSame(['c.net'], array_column($this->domains->listForProject(7), 'name'));
        $this->assertSame(11, (int) $this->domains->listForProject(7)[0]['assignee_id']);
    }

    public function testUpdateOnlyChangesProjectAndAssignee(): void
    {
        $id = $this->domains->create('example.com', 3);
        $this->pdo->prepare("UPDATE deed_domains SET status = 'ok', registry_expires_at = 1800000000 WHERE id = ?")
            ->execute([$id]);
        $before = $this->domains->get($id);

        $this->domains->update($id, 7, 11);
        $after = $this->domains->get($id);
        $this->assertSame(7, (int) $after['project_id']);
        $this->assertSame(11, (int) $after['assignee_id']);
        $after['project_id'] = $before['project_id'];
        $after['assignee_id'] = $before['assignee_id'];
        $this->assertSame($before, $after);
    }

    public function testRemoveOnlyDeletesTheDomainsOwnData(): void
    {
        $projectId = $this->container['projectModel']->create(['name' => 'Renewals']);
        $taskId = $this->container['taskCreationModel']->create(['title' => 'Renew a.com', 'project_id' => $projectId]);
        $first = $this->domains->create('a.com', 3, $projectId);
        $second = $this->domains->create('b.com', 3, $projectId);
        $snapshot = $this->pdo->prepare("INSERT INTO deed_snapshots (domain_id, observed_at, data) VALUES (?, ?, '{}')");
        $renewal = $this->pdo->prepare('INSERT INTO deed_renewals (domain_id, cycle_expires_at, task_id) VALUES (?, ?, ?)');
        foreach ([$first, $second] as $id) {
            $snapshot->execute([$id, self::NOW]);
            $renewal->execute([$id, 1800000000, $taskId]);
        }

        $this->assertTrue($this->domains->remove($first));

        $this->assertNull($this->domains->get($first));
        $this->assertSame('b.com', $this->domains->get($second)['name']);
        $this->assertSame([$second], array_map('intval', $this->pdo->query('SELECT domain_id FROM deed_snapshots')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame([$second], array_map('intval', $this->pdo->query('SELECT domain_id FROM deed_renewals')->fetchAll(PDO::FETCH_COLUMN)));
        $this->assertSame('Renew a.com', $this->container['taskFinderModel']->getById($taskId)['title']);
    }

    public function testListsOrderByEarliestKnownExpiryWithNullsLast(): void
    {
        $setExpiry = $this->pdo->prepare('UPDATE deed_domains SET registry_expires_at = ?, registrar_expires_at = ? WHERE id = ?');
        foreach ([
            ['a-unknown.com', 7, null, null],
            ['b-registry.com', 7, 400, null],
            ['c-registrar.com', 7, null, 300],
            ['d-both.com', 7, 500, 200],
            ['e-other.com', 8, 100, 600],
        ] as [$name, $project, $registry, $registrar]) {
            $id = $this->domains->create($name, 3, $project);
            $setExpiry->execute([$registry, $registrar, $id]);
        }

        $projectNames = ['d-both.com', 'c-registrar.com', 'b-registry.com', 'a-unknown.com'];
        $this->assertSame($projectNames, array_column($this->domains->listForProject(7), 'name'));
        $this->assertSame(['e-other.com', ...$projectNames], array_column($this->domains->listAll(), 'name'));
    }
}
