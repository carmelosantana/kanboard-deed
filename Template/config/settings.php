<div class="page-header">
    <h2><?= t('Deed settings') ?></h2>
</div>

<form method="post" action="<?= $this->url->href('SettingsController', 'save', ['plugin' => 'Deed']) ?>">
    <?= $this->form->csrf() ?>

    <?= $this->form->label(t('Default renewal project'), 'deed_default_project_id') ?>
    <?= $this->form->select('deed_default_project_id', $projects, $values, $errors) ?>
    <p class="form-help"><?= t('Used to create renewal tasks for domains without a linked project.') ?></p>

    <?= $this->form->label(t('Renewal thresholds in days'), 'deed_thresholds') ?>
    <?= $this->form->text('deed_thresholds', $values, $errors, ['required']) ?>
    <p class="form-help"><?= t('Use comma-separated days in descending order, such as 30,14,7.') ?></p>

    <?= $this->form->label(t('Daily refresh hour'), 'deed_refresh_hour') ?>
    <?= $this->form->number('deed_refresh_hour', $values, $errors, ['required', 'min="0"', 'max="23"']) ?>
    <p class="form-help"><?= t('Uses the Kanboard application time zone.') ?></p>

    <?= $this->form->label(t('Domains per sweep'), 'deed_batch_size') ?>
    <?= $this->form->number('deed_batch_size', $values, $errors, ['required', 'min="1"', 'max="200"']) ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</form>

<div class="page-header">
    <h2><?= t('RDAP bootstrap') ?></h2>
</div>
<form method="post" action="<?= $this->url->href('SettingsController', 'refreshBootstrap', ['plugin' => 'Deed']) ?>">
    <?= $this->form->csrf() ?>
    <button type="submit" class="btn"><?= t('Refresh bootstrap') ?></button>
</form>
