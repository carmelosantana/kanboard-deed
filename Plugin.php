<?php

namespace Kanboard\Plugin\Deed;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Deed\Client\Bootstrap;
use Kanboard\Plugin\Deed\Client\HttpFetcher;
use Kanboard\Plugin\Deed\Client\RdapClient;
use Kanboard\Plugin\Deed\Console\RefreshCommand;
use Kanboard\Plugin\Deed\Model\CacheModel;
use Kanboard\Plugin\Deed\Model\DomainModel;
use Kanboard\Plugin\Deed\Model\RdapParser;
use Kanboard\Plugin\Deed\Model\RefreshService;
use Kanboard\Plugin\Deed\Model\RenewalService;
use Kanboard\Plugin\Deed\Model\SnapshotModel;
use Kanboard\Plugin\Deed\Trigger\WebCron;
use Throwable;

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
        $this->container['deedRenewalService'] = static fn ($container) => new RenewalService(
            $container['db']->getConnection(),
            $container['configModel'],
            $container['projectModel'],
            $container['taskCreationModel'],
            $container['taskStatusModel'],
            $container['commentModel']
        );
        $this->container['deedRefreshService'] = static function ($container): RefreshService {
            $batchSize = (int) $container['configModel']->get('deed_batch_size', 25);

            return new RefreshService(
                $container['db']->getConnection(),
                $container['deedDomainModel'],
                $container['deedSnapshotModel'],
                $container['deedRdap'],
                $batchSize < 1 ? 25 : min($batchSize, 200),
                static function (array $domain, int $now) use ($container): void {
                    $container['deedRenewalService']->evaluate($domain, $now);
                }
            );
        };

        $container = $this->container;
        $container['cli']->add(new RefreshCommand($container));
        $domainActions = [
            'index' => 'deed/domains',
            'add' => 'deed/domains/add',
            'bulk' => 'deed/domains/bulk',
            'refresh' => 'deed/domains/refresh',
        ];
        foreach ($domainActions as $action => $path) {
            $this->route->addRoute($path, 'DomainController', $action, 'Deed');
        }
        $this->applicationAccessMap->add('DomainController', array_keys($domainActions), Role::APP_MANAGER);

        $this->hook->on('template:dashboard:sidebar', ['template' => 'Deed:nav/dashboard_sidebar']);
        $this->hook->on('template:header:dropdown', ['template' => 'Deed:nav/user_dropdown']);
        $this->hook->on('template:layout:css', ['template' => 'plugins/Deed/Assets/css/deed.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/Deed/Assets/js/deed.js']);
        $this->hook->on('template:layout:top', [
            'template' => 'Deed:layout/webcron',
            'callable' => static function () use ($container): array {
                try {
                    (new WebCron($container))->maybeRun();
                } catch (Throwable $exception) {
                    $container['logger']->error('Deed web-cron failed: '.$exception->getMessage());
                }

                return [];
            },
        ]);
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
