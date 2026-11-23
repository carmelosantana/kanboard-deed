<?php

namespace Kanboard\Plugin\Deed\Test;

use Kanboard\Plugin\Deed\Plugin;
use KanboardTests\units\Base;
use stdClass;
use Symfony\Component\Console\Tester\CommandTester;
use function Kanboard\Plugin\Deed\Schema\version_1;

require_once 'tests/units/Base.php';
require_once __DIR__.'/ReleasesConnection.php';

class RefreshCommandTest extends Base
{
    use ReleasesConnection;

    private CommandTester $command;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        version_1($this->container['db']->getConnection());
        (new Plugin($this->container))->initialize();
        $this->command = new CommandTester($this->container['cli']->find('deed:refresh'));
    }

    public function testCommandPrintsSweepCounts(): void
    {
        $service = $this->getMockBuilder(stdClass::class)->addMethods(['sweep'])->getMock();
        $service->expects($this->once())->method('sweep')->willReturn([
            'refreshed' => 3, 'failed' => 2, 'skipped' => 1,
        ]);
        $this->container['deedRefreshService'] = $service;

        $this->assertSame(0, $this->command->execute([]));
        $this->assertSame("Refreshed 3, failed 2, skipped 1\n", $this->command->getDisplay(true));
    }

    public function testDomainOptionRefreshesOnlyTheNamedDomain(): void
    {
        $domains = $this->container['deedDomainModel'];
        $domains->create('other.com', 1);
        $id = $domains->create('x.com', 1);
        $service = $this->getMockBuilder(stdClass::class)->addMethods(['sweep', 'refresh'])->getMock();
        $service->expects($this->never())->method('sweep');
        $service->expects($this->once())->method('refresh')->with($id)->willReturn(['status' => 'ok']);
        $this->container['deedRefreshService'] = $service;

        $this->assertSame(0, $this->command->execute(['--domain' => 'X.COM.']));
        $this->assertSame("Refreshed 1, failed 0, skipped 0\n", $this->command->getDisplay(true));
    }

    public function testUnwatchedDomainDoesNotTriggerASweep(): void
    {
        $service = $this->getMockBuilder(stdClass::class)->addMethods(['sweep', 'refresh'])->getMock();
        $service->expects($this->never())->method('sweep');
        $service->expects($this->never())->method('refresh');
        $this->container['deedRefreshService'] = $service;

        $this->assertSame(1, $this->command->execute(['--domain' => 'unwatched.com']));
        $this->assertStringContainsString('unwatched.com', $this->command->getDisplay(true));
    }
}
