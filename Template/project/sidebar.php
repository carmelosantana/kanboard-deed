<?php if ($isMember): ?>
    <li <?= $this->app->checkMenuSelection('ProjectDomainsController', 'index', 'Deed') ?>>
        <?= $this->url->link(t('Domains'), 'ProjectDomainsController', 'index', ['plugin' => 'Deed', 'project_id' => $project['id']]) ?>
    </li>
<?php endif ?>
