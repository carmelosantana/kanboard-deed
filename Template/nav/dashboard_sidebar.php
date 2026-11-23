<?php if ($this->user->hasAccess('DomainController', 'index')): ?>
    <li <?= $this->app->checkMenuSelection('DomainController', '', 'Deed') ?>>
        <?= $this->url->link(t('Domains'), 'DomainController', 'index', ['plugin' => 'Deed']) ?>
    </li>
<?php endif ?>
<?php if ($this->user->hasAccess('LookupController', 'index')): ?>
    <li <?= $this->app->checkMenuSelection('LookupController', 'index', 'Deed') ?>>
        <?= $this->url->link(t('Lookup'), 'LookupController', 'index', ['plugin' => 'Deed']) ?>
    </li>
<?php endif ?>
