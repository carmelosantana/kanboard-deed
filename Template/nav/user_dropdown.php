<?php if ($this->user->hasAccess('DomainController', 'index')): ?>
    <li><?= $this->url->icon('globe', t('Domains'), 'DomainController', 'index', ['plugin' => 'Deed']) ?></li>
<?php endif ?>
<?php if ($this->user->hasAccess('LookupController', 'index')): ?>
    <li><?= $this->url->icon('search', t('Lookup'), 'LookupController', 'index', ['plugin' => 'Deed']) ?></li>
<?php endif ?>
