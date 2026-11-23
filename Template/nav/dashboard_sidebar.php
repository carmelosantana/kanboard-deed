<?php if ($this->user->hasAccess('DomainController', 'index')): ?>
    <li <?= $this->app->checkMenuSelection('DomainController', '', 'Deed') ?>>
        <?= $this->url->link(t('Domains'), 'DomainController', 'index', ['plugin' => 'Deed']) ?>
    </li>
<?php endif ?>
