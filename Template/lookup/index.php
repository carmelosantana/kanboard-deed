<div class="page-header">
    <h2><?= t('Lookup') ?></h2>
</div>

<form method="post" action="<?= $this->url->href('LookupController', 'index', ['plugin' => 'Deed']) ?>">
    <?= $this->form->csrf() ?>
    <?= $this->form->label(t('Domain name, IP address or ASN'), 'query') ?>
    <?= $this->form->text('query', ['query' => $query], [], ['required', 'autocapitalize="none"', 'spellcheck="false"']) ?>
    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Look up') ?></button>
    </div>
</form>

<?php if ($message !== null): ?>
    <p class="alert alert-error" role="alert"><?= $this->text->e($message) ?></p>
<?php endif ?>

<?php if ($result !== null && ($result['record']['status'] === 'ok' || $result['canAdd'])): ?>
    <?= $this->render('Deed:lookup/'.$result['type'], $result) ?>
<?php endif ?>
