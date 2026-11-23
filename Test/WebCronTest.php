<?php

namespace Kanboard\Plugin\Deed\Test;

use DateTimeImmutable;
use Kanboard\Plugin\Deed\Plugin;
use Kanboard\Plugin\Deed\Trigger\WebCron;
use KanboardTests\units\Base;
use RuntimeException;
use stdClass;

require_once 'tests/units/Base.php';

class WebCronTest extends Base
{
    private const TODAY = '2026-11-23';

    public function testDoesNothingBeforeTheConfiguredHour(): void
    {
        $config = $this->container['configModel'];
        $config->save(['deed_refresh_hour' => '9', 'application_timezone' => 'America/New_York']);
        $this->container['deedRefreshService'] = static function () {
            throw new RuntimeException('Sweep should not be resolved');
        };

        $this->assertFalse((new WebCron($this->container))->maybeRun(new DateTimeImmutable(self::TODAY.' 13:59:59Z')));
        $this->assertSame('', $config->getOption('deed_last_sweep'));
    }

    public function testDoesNothingWhenTodayIsAlreadyClaimed(): void
    {
        $this->container['configModel']->save(['deed_last_sweep' => self::TODAY]);
        $this->container['deedRefreshService'] = static function () {
            throw new RuntimeException('Sweep should not be resolved');
        };

        $this->assertFalse((new WebCron($this->container))->maybeRun(new DateTimeImmutable(self::TODAY.' 12:00:00')));
    }

    public function testClaimsTheDayBeforeSweepingAndKeepsTheStampOnFailure(): void
    {
        $config = $this->container['configModel'];
        $config->save(['deed_last_sweep' => '2026-11-22']);
        $now = new DateTimeImmutable(self::TODAY.' 06:00:00');
        $cron = new WebCron($this->container);
        $service = $this->getMockBuilder(stdClass::class)->addMethods(['sweep'])->getMock();
        $service->expects($this->once())->method('sweep')->willReturnCallback(function () use ($config, $now) {
            $this->assertSame(self::TODAY, $config->getOption('deed_last_sweep'));
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
        $this->assertSame(self::TODAY, $config->getOption('deed_last_sweep'));
        $this->assertFalse($cron->maybeRun($now));
    }

    public function testLayoutHookClaimsTheFirstDayAndRendersNothing(): void
    {
        (new Plugin($this->container))->initialize();
        $config = $this->container['configModel'];
        $config->save(['deed_refresh_hour' => '0']);
        $service = $this->getMockBuilder(stdClass::class)->addMethods(['sweep'])->getMock();
        $service->expects($this->once())->method('sweep');
        $this->container['deedRefreshService'] = $service;

        $hook = $this->container['helper']->getHelper('hook');
        $this->assertSame('', $hook->render('template:layout:top'));
        $this->assertSame(date('Y-m-d'), $config->getOption('deed_last_sweep'));
        $this->assertSame('', $hook->render('template:layout:top'));
    }
}
