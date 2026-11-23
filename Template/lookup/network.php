<section class="panel">
    <h3><?= $this->text->e($title ?? t('IP network')) ?> · <?= $this->text->e($value) ?></h3>
    <dl>
        <?php foreach ([
            'name' => t('Name'),
            'handle' => t('Handle'),
            'range' => t('Range'),
            'country' => t('Country'),
            'registrant' => t('Registrant'),
            'abuse_email' => t('Abuse contact'),
        ] as $field => $label): ?>
            <?php if (isset($record[$field]) && is_scalar($record[$field]) && $record[$field] !== ''): ?>
                <dt><?= $this->text->e($label) ?></dt>
                <dd><?= $this->text->e($record[$field]) ?></dd>
            <?php endif ?>
        <?php endforeach ?>
    </dl>
</section>
