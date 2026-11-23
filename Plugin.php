<?php

namespace Kanboard\Plugin\Deed;

use Kanboard\Core\Plugin\Base;
use Kanboard\Plugin\Deed\Client\Bootstrap;
use Kanboard\Plugin\Deed\Client\HttpFetcher;
use Kanboard\Plugin\Deed\Client\RdapClient;
use Kanboard\Plugin\Deed\Model\CacheModel;
use Kanboard\Plugin\Deed\Model\DomainModel;
use Kanboard\Plugin\Deed\Model\RdapParser;
use Kanboard\Plugin\Deed\Model\RefreshService;
use Kanboard\Plugin\Deed\Model\SnapshotModel;

class Plugin extends Base
{
    public function initialize()
    {
        $version = $this->getPluginVersion();
        $this->container['deedCache'] = static fn ($container) => new CacheModel($container['db']->getConnection());
        $this->container['deedDomainModel'] = static fn ($container) => new DomainModel($container['db']->getConnection());
        $this->container['deedHttp'] = static fn ($container) => new HttpFetcher(
            $version,
            $container['configModel']->get('application_url')
        );
        $this->container['deedParser'] = static fn () => new RdapParser();
        $this->container['deedBootstrap'] = static fn ($container) => new Bootstrap(
            $container['deedHttp'],
            $container['deedCache']
        );
        $this->container['deedRdap'] = static fn ($container) => new RdapClient(
            $container['deedHttp'],
            $container['deedBootstrap'],
            $container['deedParser'],
            $container['deedCache']
        );
        $this->container['deedSnapshotModel'] = static fn ($container) => new SnapshotModel(
            $container['db']->getConnection()
        );
        $this->container['deedRefreshService'] = static fn ($container) => new RefreshService(
            $container['db']->getConnection(),
            $container['deedDomainModel'],
            $container['deedSnapshotModel'],
            $container['deedRdap'],
            (int) $container['configModel']->get('deed_batch_size', 25),
            static function (array $domain, int $now) use ($container): void {
                if (isset($container['deedRenewalService'])) {
                    $container['deedRenewalService']->evaluate($domain, $now);
                }
            }
        );
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
