<?php

namespace Kanboard\Plugin\Deed\Test;

use DateTimeImmutable;
use Kanboard\Plugin\Deed\Client\RdapClient;
use Kanboard\Plugin\Deed\Model\DomainModel;
use Kanboard\Plugin\Deed\Model\RefreshService;
use Kanboard\Plugin\Deed\Model\SnapshotModel;
use Kanboard\Plugin\Deed\Plugin;
use Kanboard\Plugin\Deed\Trigger\WebCron;
use KanboardTests\units\Base;
use RuntimeException;
use stdClass;
use function Kanboard\Plugin\Deed\Schema\version_1;

require_once 'tests/units/Base.php';

class WebCronTest extends Base
{
    private const TODAY = '2026-11-23';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        version_1($this->container['db']->getConnection());
    }

    public function testDoesNothingBeforeTheConfiguredHour(): void
    {
        $now = new DateTimeImmutable(self::TODAY.' 13:59:59Z');
        (new DomainModel($this->container['db']->getConnection(), $now->getTimestamp(...)))->create('example.com', 1);
        $config = $this->container['configModel'];
        $config->save(['deed_refresh_hour' => '9', 'application_timezone' => 'America/New_York']);
        $this->container['deedRefreshService'] = static function () {
            throw new RuntimeException('Sweep should not be resolved');
        };

        $this->assertFalse((new WebCron($this->container))->maybeRun($now));
        $this->assertSame('', $config->getOption('deed_last_sweep'));
    }

    public function testHonorsALegacyDailyClaimForItsRecordedDay(): void
    {
        $now = new DateTimeImmutable(self::TODAY.' 12:00:00Z');
        (new DomainModel($this->container['db']->getConnection(), $now->getTimestamp(...)))->create('example.com', 1);
        $this->container['configModel']->save(['deed_last_sweep' => self::TODAY]);
        $this->container['deedRefreshService'] = static function () {
            throw new RuntimeException('Sweep should not be resolved');
        };

        $this->assertFalse((new WebCron($this->container))->maybeRun($now));
    }

    public function testBudgetedSweepsResumeAtFiveMinutesWhileDomainsAreDue(): void
    {
        $start = new DateTimeImmutable(self::TODAY.' 12:00:00Z');
        $timestamp = $start->getTimestamp();
        $clock = static function () use (&$timestamp): int {
            return $timestamp;
        };
        $pdo = $this->container['db']->getConnection();
        $domains = new DomainModel($pdo, $clock);
        $names = ['first.com', 'second.com', 'third.com'];
        $ids = array_map(static fn (string $name): int => $domains->create($name, 1), $names);
        $lookups = [];
        $rdap = $this->createMock(RdapClient::class);
        $rdap->method('lookupDomain')->willReturnCallback(static function (string $name) use (&$timestamp, &$lookups): array {
            $lookups[] = $name;
            $timestamp += 6;

            return ['status' => 'not_found'];
        });
        $this->container['deedRefreshService'] = new RefreshService(
            $pdo,
            $domains,
            new SnapshotModel($pdo),
            $rdap,
            clock: $clock
        );
        $cron = new WebCron($this->container);
        $config = $this->container['configModel'];

        $this->assertTrue($cron->maybeRun($start));
        $this->assertSame(['first.com', 'second.com'], $lookups);
        $this->assertSame('pending', $domains->get($ids[2])['status']);
        $this->assertSame((string) $start->getTimestamp(), $config->getOption('deed_last_sweep'));

        $timestamp = $start->getTimestamp() + 299;
        $this->assertFalse($cron->maybeRun($start->setTimestamp($timestamp)));
        $this->assertSame(['first.com', 'second.com'], $lookups);

        $timestamp++;
        $resumeAt = $start->setTimestamp($timestamp);
        $this->assertTrue($cron->maybeRun($resumeAt));
        $this->assertSame($names, $lookups);
        $this->assertSame('not_found', $domains->get($ids[2])['status']);
        $this->assertFalse($cron->maybeRun($resumeAt->modify('+5 minutes')));
        $this->assertSame((string) $resumeAt->getTimestamp(), $config->getOption('deed_last_sweep'));
    }

    public function testClaimsTheSlotBeforeSweepingAndKeepsTheStampOnFailure(): void
    {
        $config = $this->container['configModel'];
        $config->save(['deed_last_sweep' => '2026-11-22', 'deed_web_budget_seconds' => '4']);
        $now = new DateTimeImmutable(self::TODAY.' 06:00:00Z');
        (new DomainModel($this->container['db']->getConnection(), $now->getTimestamp(...)))->create('example.com', 1);
        $cron = new WebCron($this->container);
        $service = $this->getMockBuilder(stdClass::class)->addMethods(['sweep'])->getMock();
        $service->expects($this->once())->method('sweep')->with(null, 4)->willReturnCallback(function () use ($config, $now) {
            $this->assertSame((string) $now->getTimestamp(), $config->getOption('deed_last_sweep'));
            $this->assertFalse((new WebCron($this->container))->maybeRun($now));
            throw new RuntimeException('Sweep failed');
        });
        $this->container['deedRefreshService'] = $service;

        try {
            $cron->maybeRun($now);
            $this->fail('Expected the sweep to throw');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sweep failed', $exception->getMessage());
        }
        $this->assertSame((string) $now->getTimestamp(), $config->getOption('deed_last_sweep'));
        $this->assertFalse($cron->maybeRun($now));
    }

    public function testLayoutHookClaimsASlotAndRendersNothing(): void
    {
        (new Plugin($this->container))->initialize();
        $config = $this->container['configModel'];
        $config->save(['deed_refresh_hour' => '0']);
        $this->container['deedDomainModel']->create('example.com', 1);
        $service = $this->getMockBuilder(stdClass::class)->addMethods(['sweep'])->getMock();
        $service->expects($this->once())->method('sweep');
        $this->container['deedRefreshService'] = $service;

        $hook = $this->container['helper']->getHelper('hook');
        $before = time();
        $this->assertSame('', $hook->render('template:layout:top'));
        $claimedAt = (int) $config->getOption('deed_last_sweep');
        $this->assertGreaterThanOrEqual($before, $claimedAt);
        $this->assertLessThanOrEqual(time(), $claimedAt);
        $this->assertSame('', $hook->render('template:layout:top'));
    }
}
