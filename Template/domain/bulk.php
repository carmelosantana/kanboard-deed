<div class="page-header">
    <h2><?= t('Bulk add') ?></h2>
</div>

<?php if ($error !== ''): ?>
    <p class="alert alert-error" role="alert"><?= $this->text->e($error) ?></p>
<?php endif ?>

<?php if ($result !== null): ?>
    <ul class="alert alert-info deed-bulk-result" role="status">
        <li><?= t('Added: %d', count($result['added'])) ?></li>
        <li><?= t('Existing: %d', count($result['existing'])) ?></li>
        <li><?= t('Invalid: %d', count($result['invalid'])) ?></li>
    </ul>
    <?php if ($result['invalid'] !== []): ?>
        <ul>
            <?php foreach ($result['invalid'] as $entry): ?>
                <li><?= $this->text->e($entry) ?></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
<?php endif ?>

<form method="post" action="<?= $this->url->href('DomainController', 'bulk', ['plugin' => 'Deed']) ?>">
    <?= $this->form->csrf() ?>
    <?= $this->form->label(t('Domain names'), 'domains') ?>
    <textarea id="form-domains" name="domains" rows="10" required autocapitalize="none" spellcheck="false"
              placeholder="<?= $this->text->e(t('Paste one domain per line')) ?>" aria-describedby="deed-bulk-hint"><?= $this->text->e($input) ?></textarea>
    <p id="deed-bulk-hint"><?= t('To copy domains from PowerBank, run this command and paste the output.') ?>
        <code>jq -r '.records[].domain' ~/PowerBank/data/domains.json</code>
    </p>
    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Add domains') ?></button>
        <?= $this->url->link(t('Back to Domains'), 'DomainController', 'index', ['plugin' => 'Deed']) ?>
    </div>
</form>
