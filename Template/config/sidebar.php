<?php if ($this->user->isAdmin()): ?>
    <li <?= $this->app->checkMenuSelection('DeedSettingsController', '', 'Deed') ?>>
        <?= $this->url->link(t('Deed'), 'DeedSettingsController', 'index', ['plugin' => 'Deed']) ?>
    </li>
<?php endif ?>
