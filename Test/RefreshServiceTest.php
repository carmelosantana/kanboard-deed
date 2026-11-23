<?php

namespace Kanboard\Plugin\Deed\Test;

use Closure;
use Kanboard\Plugin\Deed\Client\RdapClient;
use Kanboard\Plugin\Deed\Model\DomainModel;
use Kanboard\Plugin\Deed\Model\RefreshService;
use Kanboard\Plugin\Deed\Model\SnapshotModel;
use Kanboard\Plugin\Deed\Plugin;
use KanboardTests\units\Base;
use PDO;
use PDOException;
use function Kanboard\Plugin\Deed\Schema\version_1;

require_once 'tests/units/Base.php';

class RefreshServiceTest extends Base
{
    private const DAY = 86400;
    private const RECORD = [
        'status' => 'ok',
        'registrar' => 'Example Registrar',
        'registered_at' => 1600000000,
        'registry_expires_at' => 1800000000,
        'registrar_expires_at' => 1799999999,
        'statuses' => ['transfer prohibited', 'active'],
        'nameservers' => ['NS2.Example.com', 'ns1.example.com'],
        'rdap_url' => 'https://rdap.example.com/domain/example.com',
    ];

    private int $now = 1700000000;
    private PDO $pdo;
    private DomainModel $domains;
    private SnapshotModel $snapshots;
    private RdapClient $rdap;
    private array $renewals = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        $this->pdo = $this->container['db']->getConnection();
        version_1($this->pdo);
        $this->domains = new DomainModel($this->pdo, fn () => $this->now);
        $this->rdap = new class extends RdapClient {
            public array $result = [];
            public array $lookups = [];
            public ?Closure $duringLookup = null;

            public function __construct()
            {
            }

            public function lookupDomain(string $domain, bool $useCache = true): array
            {
                $this->lookups[] = [$domain, $useCache];
                $result = $this->result;
                ($this->duringLookup ?? static fn () => null)();

                return $result;
            }
        };
    }

    private function service(int $batchSize = 25): RefreshService
    {
        $this->snapshots = new SnapshotModel($this->pdo);

        return new RefreshService(
            $this->pdo,
            $this->domains,
            $this->snapshots,
            $this->rdap,
            $batchSize,
            function (array $domain, int $now): void {
                $this->renewals[] = [$domain, $now];
            },
            fn () => $this->now
        );
    }

    public function testSuccessfulRefreshStoresRecordAndSnapshotsOnlyChanges(): void
    {
        $id = $this->domains->create('example.com', 1);
        $service = $this->service();
        $this->rdap->result = self::RECORD;
        $service->refresh($id);
        $record = $this->domains->get($id);

        foreach (['status', 'registrar', 'rdap_url'] as $field) {
            $this->assertSame(self::RECORD[$field], $record[$field]);
        }
        foreach (['registered_at', 'registry_expires_at', 'registrar_expires_at'] as $field) {
            $this->assertSame(self::RECORD[$field], (int) $record[$field]);
        }
        $this->assertSame(['active', 'transfer prohibited'], json_decode($record['statuses'], true));
        $this->assertSame(['ns1.example.com', 'ns2.example.com'], json_decode($record['nameservers'], true));
        $this->assertSame($this->now, (int) $record['last_checked_at']);
        $this->assertSame($this->now, (int) $record['last_success_at']);
        $this->assertSame($this->now + self::DAY, (int) $record['next_check_at']);
        $this->assertSame(0, (int) $record['fail_count']);
        $this->assertNull($record['last_error']);
        $this->assertNotEmpty($record['fingerprint']);
        $this->assertSame([['example.com', false]], $this->rdap->lookups);
        $this->assertCount(1, $this->snapshots->listForDomain($id));

        $this->now += self::DAY;
        $this->rdap->result['nameservers'] = ['NS1.example.com', 'ns2.example.COM'];
        $this->rdap->result['statuses'] = ['active', 'transfer prohibited'];
        $service->refresh($id);
        $this->assertSame($record['fingerprint'], $this->domains->get($id)['fingerprint']);
        $this->assertCount(1, $this->snapshots->listForDomain($id));

        $this->now += self::DAY;
        $this->rdap->result['nameservers'] = ['ns3.example.com'];
        $service->refresh($id);
        $history = $this->snapshots->listForDomain($id);
        $this->assertCount(2, $history);
        $this->assertSame($this->now, (int) $history[0]['observed_at']);
        $this->assertNotSame($record['fingerprint'], $this->domains->get($id)['fingerprint']);
        $this->assertSame([
            'nameservers' => ['before' => ['ns1.example.com', 'ns2.example.com'], 'after' => ['ns3.example.com']],
        ], SnapshotModel::diff($history[1]['data'], $history[0]['data']));
    }

    public function testErrorsPreserveKnownRecordApplyBackoffAndResetOnRecovery(): void
    {
        $id = $this->domains->create('example.com', 1);
        $service = $this->service();
        $this->rdap->result = self::RECORD;
        $service->refresh($id);
        $known = $this->domains->get($id);
        $fields = array_flip([
            'registrar', 'registered_at', 'registry_expires_at', 'registrar_expires_at',
            'statuses', 'nameservers', 'rdap_url', 'fingerprint', 'last_success_at',
        ]);

        foreach ([3600, 21600, self::DAY, self::DAY, 2 * self::DAY] as $index => $backoff) {
            $this->now += 60;
            $this->rdap->result = [
                'status' => 'error',
                'error' => 'http_429',
                'retry_after' => $index === 4 ? $backoff : 30,
                'rdap_url' => 'https://other.example.com/domain/example.com',
                'registry_expires_at' => null,
            ];
            $service->refresh($id);
            $record = $this->domains->get($id);

            $this->assertSame(array_intersect_key($known, $fields), array_intersect_key($record, $fields));
            $this->assertSame('error', $record['status']);
            $this->assertSame('http_429', $record['last_error']);
            $this->assertSame($index + 1, (int) $record['fail_count']);
            $this->assertSame($this->now, (int) $record['last_checked_at']);
            $this->assertSame($this->now + $backoff, (int) $record['next_check_at']);
        }

        $this->now = (int) $known['last_success_at'] + 3 * self::DAY;
        $this->assertFalse($service->isStale($record));
        $this->now++;
        $this->assertTrue($service->isStale($record));
        $this->assertFalse($service->isStale(['last_success_at' => null]));

        $this->rdap->result = self::RECORD;
        $service->refresh($id);
        $record = $this->domains->get($id);
        $this->assertSame(0, (int) $record['fail_count']);
        $this->assertNull($record['last_error']);
        $this->assertFalse($service->isStale($record));
        $this->assertCount(1, $this->snapshots->listForDomain($id));
    }

    public function testIncompleteSuccessKeepsKnownValuesAndNotesReferralError(): void
    {
        $id = $this->domains->create('example.com', 1);
        $service = $this->service();
        $this->rdap->result = self::RECORD;
        $service->refresh($id);

        $this->now += self::DAY;
        $this->rdap->result = array_replace(self::RECORD, [
            'registrar' => null,
            'registrar_expires_at' => null,
            'registry_expires_at' => 1800000001,
            'statuses' => [],
            'referral_error' => 'http_503',
        ]);
        $service->refresh($id);
        $record = $this->domains->get($id);
        $this->assertSame('ok', $record['status']);
        $this->assertSame('http_503', $record['last_error']);
        $this->assertSame(self::RECORD['registrar'], $record['registrar']);
        $this->assertSame(self::RECORD['registrar_expires_at'], (int) $record['registrar_expires_at']);
        $this->assertSame(1800000001, (int) $record['registry_expires_at']);
        $this->assertSame('[]', $record['statuses']);
        $this->assertSame($this->now, (int) $record['last_success_at']);
        $this->assertCount(2, $this->snapshots->listForDomain($id));
    }

    public function testNotFoundAndUnsupportedKeepKnownExpiryAndHistory(): void
    {
        $id = $this->domains->create('example.com', 1);
        $service = $this->service();
        $this->rdap->result = self::RECORD;
        $service->refresh($id);
        $success = $this->now;

        foreach (['not_found' => self::DAY, 'unsupported' => 7 * self::DAY] as $status => $delay) {
            $this->now += self::DAY;
            $this->rdap->result = ['status' => $status];
            $service->refresh($id);
            $record = $this->domains->get($id);
            $this->assertSame($status, $record['status']);
            $this->assertSame(self::RECORD['registry_expires_at'], (int) $record['registry_expires_at']);
            $this->assertSame(self::RECORD['registrar_expires_at'], (int) $record['registrar_expires_at']);
            $this->assertSame($success, (int) $record['last_success_at']);
            $this->assertSame($this->now + $delay, (int) $record['next_check_at']);
            $this->assertCount(1, $this->snapshots->listForDomain($id));
        }
    }

    public function testSweepUsesDueOrderAndBothLimitsAndEvaluatesPersistedRows(): void
    {
        $schedule = $this->pdo->prepare('UPDATE deed_domains SET next_check_at = ? WHERE id = ?');
        foreach (['soon' => -10, 'future' => 60, 'oldest' => -30, 'middle' => -20, 'due-now' => 0] as $label => $offset) {
            $id = $this->domains->create($label.'.com', 1);
            $schedule->execute([$this->now + $offset, $id]);
        }
        $service = $this->service(2);
        $this->rdap->result = ['status' => 'error', 'error' => 'timeout'];

        $this->assertSame(['refreshed' => 0, 'failed' => 2, 'skipped' => 0], $service->sweep(4));
        $this->assertSame([['oldest.com', false], ['middle.com', false]], $this->rdap->lookups);
        $this->assertCount(2, $this->renewals);
        foreach ($this->renewals as [$record, $now]) {
            $this->assertSame('error', $record['status']);
            $this->assertSame('error', $this->domains->get($record['id'])['status']);
            $this->assertSame($this->now, $now);
        }

        $this->rdap->result = ['status' => 'unsupported'];
        $this->assertSame(['refreshed' => 0, 'failed' => 0, 'skipped' => 1], $service->sweep(1));
        $this->assertSame(['soon.com', false], $this->rdap->lookups[2]);

        $this->rdap->result = self::RECORD;
        $this->assertSame(['refreshed' => 1, 'failed' => 0, 'skipped' => 0], $service->sweep());
        $this->assertSame(['due-now.com', false], $this->rdap->lookups[3]);
        $this->assertCount(4, $this->rdap->lookups);
        $this->assertCount(4, $this->renewals);
    }

    public function testSnapshotFailureRollsBackRecordUpdate(): void
    {
        $id = $this->domains->create('example.com', 1);
        $service = $this->service();
        $this->rdap->result = self::RECORD;
        $before = $this->domains->get($id);
        $this->pdo->exec('DROP TABLE deed_snapshots');

        try {
            $service->refresh($id);
            $this->fail('Expected a snapshot write failure.');
        } catch (PDOException) {
            $this->assertSame($before, $this->domains->get($id));
            $this->assertFalse($this->pdo->inTransaction());
            $this->assertSame([], $this->renewals);
        }
    }

    public function testInFlightLookupDoesNotOverwriteAnotherRefresh(): void
    {
        $id = $this->domains->create('example.com', 1);
        $service = $this->service();
        $this->rdap->result = self::RECORD;
        $this->rdap->duringLookup = function () use ($id, $service): void {
            $this->rdap->duringLookup = null;
            ++$this->now;
            $this->rdap->result['registry_expires_at']++;
            $service->refresh($id);
        };

        $this->assertNull($service->refresh($id));
        $this->assertSame(self::RECORD['registry_expires_at'] + 1, (int) $this->domains->get($id)['registry_expires_at']);
        $this->assertCount(1, $this->snapshots->listForDomain($id));
        $this->assertCount(1, $this->renewals);
    }

    public function testDiffIgnoresUnchangedFieldsAndShowsAddedAndClearedValues(): void
    {
        $this->assertSame([
            'registrar' => ['before' => 'Old Registrar', 'after' => null],
            'registry_expires_at' => ['before' => null, 'after' => 1800000000],
        ], SnapshotModel::diff(
            ['registrar' => 'Old Registrar', 'nameservers' => ['ns1.example.com']],
            ['registry_expires_at' => 1800000000, 'nameservers' => ['ns1.example.com']]
        ));
    }

    public function testPluginWiresRefreshServiceWithConfiguredBatchSizeAndRenewalEvaluator(): void
    {
        (new Plugin($this->container))->initialize();
        $this->container['configModel']->save(['deed_batch_size' => '1']);
        $this->container['deedRdap'] = $this->rdap;
        $this->container['deedRenewalService'] = $spy = new class {
            public array $domains = [];

            public function evaluate(array $domain, int $now): void
            {
                $this->domains[] = $domain['name'];
            }
        };
        $this->domains->create('first.com', 1);
        $this->domains->create('second.com', 1);
        $this->rdap->result = self::RECORD;

        $this->assertInstanceOf(SnapshotModel::class, $this->container['deedSnapshotModel']);
        $this->assertSame(['refreshed' => 1, 'failed' => 0, 'skipped' => 0], $this->container['deedRefreshService']->sweep());
        $this->assertSame(['first.com'], $spy->domains);
    }
}
