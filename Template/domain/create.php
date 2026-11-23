<div class="page-header">
    <h2><?= t('Add domain') ?></h2>
</div>

<?php if ($error !== ''): ?>
    <p class="alert alert-error" role="alert"><?= $this->text->e($error) ?></p>
<?php endif ?>

<form method="post" action="<?= $this->url->href('DomainController', 'add', ['plugin' => 'Deed']) ?>">
    <?= $this->form->csrf() ?>
    <?= $this->form->label(t('Domain name'), 'domain') ?>
    <?= $this->form->text('domain', ['domain' => $domain], [], ['required', 'autocapitalize="none"', 'spellcheck="false"']) ?>
    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Add domain') ?></button>
        <?= $this->url->link(t('Cancel'), 'DomainController', 'index', ['plugin' => 'Deed']) ?>
    </div>
</form>
