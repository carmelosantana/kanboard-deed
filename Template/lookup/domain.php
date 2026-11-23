<section class="panel">
    <h3><?= $this->text->e($value) ?></h3>
    <?php if ($record['status'] === 'ok'): ?>
        <?php
        $statuses = $record['statuses'] ?? [];
        $fields = [
            t('Registrar') => $record['registrar'] ?? null,
            t('Registered on') => isset($record['registered_at']) ? gmdate('Y-m-d', $record['registered_at']) : null,
            t('Registry expiry') => isset($record['registry_expires_at']) ? gmdate('Y-m-d', $record['registry_expires_at']) : null,
            t('Registrar expiry') => isset($record['registrar_expires_at']) ? gmdate('Y-m-d', $record['registrar_expires_at']) : null,
            t('Registration statuses') => is_array($statuses) ? implode(', ', array_filter($statuses, 'is_string')) : null,
            t('Nameservers') => implode(', ', $record['nameservers'] ?? []),
        ];
        ?>
        <dl>
            <?php foreach ($fields as $label => $field): ?>
                <dt><?= $this->text->e($label) ?></dt>
                <dd><?= $this->text->e($field === null || $field === '' ? '—' : $field) ?></dd>
            <?php endforeach ?>
        </dl>
        <?php if (!empty($record['referral_error'])): ?>
            <p><?= t('Registrar data unavailable') ?></p>
        <?php endif ?>
    <?php endif ?>

    <?php if ($canAdd): ?>
        <form method="post" action="<?= $this->url->href('DomainController', 'add', ['plugin' => 'Deed']) ?>">
            <?= $this->form->csrf() ?>
            <?= $this->form->hidden('domain', ['domain' => $value]) ?>
            <button type="submit" class="btn"><?= t('Add to watchlist') ?></button>
        </form>
    <?php endif ?>
</section>
