<?php

namespace Kanboard\Plugin\Deed\Test;

use Closure;
use Kanboard\Plugin\Deed\Client\HttpResponse;
use Kanboard\Plugin\Deed\Client\RdapClient;
use Kanboard\Plugin\Deed\Model\DomainModel;
use Kanboard\Plugin\Deed\Model\RefreshService;
use Kanboard\Plugin\Deed\Model\SnapshotModel;
use Kanboard\Plugin\Deed\Plugin;
use KanboardTests\units\Base;
use PDO;
use PDOException;
use RuntimeException;
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
                ($this->duringLookup ?? static fn () => null)($domain);

                return $result;
            }
        };
    }

    private function service(int $batchSize = 25, ?Closure $evaluateRenewal = null): RefreshService
    {
        $this->snapshots = new SnapshotModel($this->pdo);

        return new RefreshService(
            $this->pdo,
            $this->domains,
            $this->snapshots,
            $this->rdap,
            $batchSize,
            $evaluateRenewal ?? function (array $domain, int $now): void {
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

        foreach ([7200, 21600, self::DAY, self::DAY] as $index => $backoff) {
            $this->now += 60;
            $this->rdap->result = [
                'status' => 'error',
                'error' => 'http_429',
                'retry_after' => $index === 0 ? 7200 : 30,
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

    /** @dataProvider longRetryAfterHeaders */
    public function testLongRetryAfterIsCappedAtOneDay(string $header): void
    {
        $service = $this->service();
        $id = $this->domains->create('example.com', 1);
        $response = new HttpResponse(429, '', ['Retry-After' => $header], $this->now);
        $delay = $response->retryAfter();
        $this->assertGreaterThan(self::DAY, $delay);
        $this->rdap->result = ['status' => 'error', 'error' => 'http_429', 'retry_after' => $delay];

        $service->refresh($id);

        $this->assertSame($this->now + self::DAY, (int) $this->domains->get($id)['next_check_at']);
    }

    public static function longRetryAfterHeaders(): iterable
    {
        yield 'seconds' => ['999999999999999999999'];
        yield 'HTTP-date' => ['Tue, 01 Jan 2030 00:00:00 GMT'];
    }

    public function testDomainWithNoSuccessfulRefreshBecomesStaleThreeDaysAfterCreation(): void
    {
        $id = $this->domains->create('example.com', 1);
        $service = $this->service();
        $this->rdap->result = ['status' => 'error', 'error' => 'timeout'];

        $this->now += 3 * self::DAY;
        $record = $service->refresh($id);
        $this->assertNull($record['last_success_at']);
        $this->assertFalse($service->isStale($record));
        ++$this->now;
        $this->assertTrue($service->isStale($record));
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

    /** @dataProvider successfulResultsWithoutRegistrarExpiry */
    public function testSuccessfulLookupWithoutRegistrarExpiryClearsOldValue(array $result): void
    {
        $id = $this->domains->create('example.com', 1);
        $service = $this->service();
        $this->rdap->result = self::RECORD;
        $service->refresh($id);

        $this->now += self::DAY;
        $this->rdap->result = $result;
        $service->refresh($id);

        $record = $this->domains->get($id);
        $this->assertNull($record['registrar_expires_at']);
        $this->assertSame(self::RECORD['registry_expires_at'], (int) $record['registry_expires_at']);
        $this->assertNull($this->renewals[1][0]['registrar_expires_at']);
        $history = $this->snapshots->listForDomain($id);
        $this->assertCount(2, $history);
        $this->assertSame([
            'registrar_expires_at' => ['before' => self::RECORD['registrar_expires_at'], 'after' => null],
        ], SnapshotModel::diff($history[1]['data'], $history[0]['data']));
    }

    public static function successfulResultsWithoutRegistrarExpiry(): iterable
    {
        $record = array_replace(self::RECORD, ['registrar_expires_at' => null, 'referral_error' => null]);
        yield 'successful referral' => [$record + ['related_url' => 'https://rdap.example.net/domain/example.com']];
        unset($record['registrar_expires_at']);
        yield 'no referral' => [$record];
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

    public function testSweepSkipsDueBatchWhenBootstrapIsUnavailable(): void
    {
        $first = $this->domains->create('first.com', 1);
        $service = $this->service(2);
        $this->rdap->result = ['status' => 'error', 'error' => 'timeout'];
        $service->refresh($first);
        $this->now += 3600;
        $second = $this->domains->create('second.net', 1);
        $third = $this->domains->create('third.org', 1);
        $before = array_map($this->domains->get(...), [$first, $second, $third]);
        $this->renewals = [];
        $this->rdap->lookups = [];
        $this->rdap->result = ['status' => 'error', 'error' => 'bootstrap_unavailable'];

        $this->assertSame(['refreshed' => 0, 'failed' => 0, 'skipped' => 2], $service->sweep());
        $this->assertSame([['first.com', false]], $this->rdap->lookups);
        $this->assertSame([], $this->renewals);
        $this->assertSame($before, array_map($this->domains->get(...), [$first, $second, $third]));
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

    /** @dataProvider sweepExceptionStages */
    public function testSweepSchedulesRetryAndContinuesAfterADomainThrows(string $stage): void
    {
        $first = $this->domains->create('first.com', 1);
        $service = $this->service(evaluateRenewal: function (array $record) use ($stage): void {
            $this->renewals[] = $record['name'];
            if ($stage === 'renewal' && $record['name'] === 'first.com') {
                throw new RuntimeException('renewal failed');
            }
        });
        $this->rdap->result = self::RECORD;
        if ($stage === 'lookup') {
            $this->rdap->duringLookup = static function (string $domain): void {
                if ($domain === 'first.com') {
                    throw new RuntimeException('lookup failed');
                }
            };
        }
        ++$this->now;
        $second = $this->domains->create('second.com', 1);

        $this->assertSame(['refreshed' => 1, 'failed' => 1, 'skipped' => 0], $service->sweep());
        $record = $this->domains->get($first);
        $this->assertSame('error', $record['status']);
        $this->assertSame($stage.' failed', $record['last_error']);
        $this->assertSame(1, (int) $record['fail_count']);
        $this->assertSame($this->now + 3600, (int) $record['next_check_at']);
        $this->assertSame('ok', $this->domains->get($second)['status']);
        $this->assertSame(['first.com', 'second.com'], $this->renewals);
    }

    public static function sweepExceptionStages(): iterable
    {
        yield 'lookup' => ['lookup'];
        yield 'renewal evaluation' => ['renewal'];
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

    public function testInFlightLookupKeepsUnrelatedUpdatesWithoutSkippingRefresh(): void
    {
        $id = $this->domains->create('example.com', 1);
        $service = $this->service();
        $this->rdap->result = self::RECORD;
        $this->rdap->duringLookup = function () use ($id): void {
            $this->pdo->prepare('UPDATE deed_domains SET created_by = ? WHERE id = ?')->execute([2, $id]);
        };

        $record = $service->refresh($id);

        $this->assertSame('ok', $record['status'] ?? null);
        $this->assertSame(2, (int) $record['created_by']);
        $this->assertSame(2, (int) $this->domains->get($id)['created_by']);
    }

    /** @dataProvider batchSizeSettings */
    public function testPluginNormalizesBatchSize(?string $setting, int $expected): void
    {
        (new Plugin($this->container))->initialize();
        if ($setting !== null) {
            $this->container['configModel']->save(['deed_batch_size' => $setting]);
        }
        $this->container['deedRdap'] = $this->rdap;
        $this->rdap->result = self::RECORD;
        for ($i = 0; $i <= $expected; ++$i) {
            $this->domains->create('domain-'.$i.'.com', 1);
        }

        $this->assertSame($expected, $this->container['deedRefreshService']->sweep()['refreshed']);
    }

    public static function batchSizeSettings(): iterable
    {
        yield 'unset' => [null, 25];
        yield 'empty' => ['', 25];
        yield 'zero' => ['0', 25];
        yield 'negative' => ['-5', 25];
        yield 'over the maximum' => ['250', 200];
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
