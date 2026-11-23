<?php if ($this->user->isAdmin()): ?>
    <li <?= $this->app->checkMenuSelection('SettingsController', '', 'Deed') ?>>
        <?= $this->url->link(t('Deed'), 'SettingsController', 'index', ['plugin' => 'Deed']) ?>
    </li>
<?php endif ?>
