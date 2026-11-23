<?php

namespace Kanboard\Plugin\Deed;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize()
    {
    }

    public function getPluginName(): string
    {
        return 'Deed';
    }

    public function getPluginDescription(): string
    {
        return 'Track domain registrations and renewals in Kanboard with RDAP lookups.';
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginVersion(): string
    {
        return '1.0.0';
    }

    public function getPluginHomepage(): string
    {
        return 'https://github.com/carmelosantana/kanboard-deed';
    }

    public function getPluginLicense(): string
    {
        return 'MIT';
    }

    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }
}
