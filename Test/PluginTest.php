<?php

namespace Kanboard\Plugin\Deed\Test;

use Kanboard\Plugin\Deed\Plugin;
use PHPUnit\Framework\TestCase;
use Pimple\Container;

class PluginTest extends TestCase
{
    public function testVersionsAgree(): void
    {
        $manifestPath = __DIR__.'/../plugin.json';
        $this->assertFileExists($manifestPath);
        $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        $plugin = new Plugin(new Container());
        $this->assertSame('1.0.2', $manifest['version']);
        $this->assertSame($manifest['version'], $plugin->getPluginVersion());
    }
}
