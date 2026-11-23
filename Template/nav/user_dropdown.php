<?php if ($this->user->hasAccess('DomainController', 'index')): ?>
    <li><?= $this->url->icon('globe', t('Domains'), 'DomainController', 'index', ['plugin' => 'Deed']) ?></li>
<?php endif ?>
