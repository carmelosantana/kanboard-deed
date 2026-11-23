<?php
$domainRoute = ['plugin' => 'Deed', 'domain_id' => $domain['id']];
?>
<div class="page-header">
    <h2><?= $this->text->e($title) ?></h2>
    <ul>
        <li><?= $this->url->link(t('Back to Domains'), 'DomainController', 'index', ['plugin' => 'Deed']) ?></li>
        <?php if ($view === 'detail'): ?>
            <li><?= $this->url->link(t('Edit'), 'DomainController', 'edit', $domainRoute) ?></li>
            <li><?= $this->url->link(t('Remove'), 'DomainController', 'remove', $domainRoute) ?></li>
        <?php endif ?>
    </ul>
</div>

<?php if ($view === 'edit'): ?>
    <form method="post" action="<?= $this->url->href('DomainController', 'edit', $domainRoute) ?>">
        <?= $this->form->csrf() ?>
        <?= $this->form->label(t('Project'), 'project_id') ?>
        <?= $this->form->select('project_id', $projects, $selection) ?>
        <?= $this->form->label(t('Assignee'), 'assignee_id') ?>
        <?= $this->form->select('assignee_id', $assignees, $domain) ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
            <?= $this->url->link(t('Cancel'), 'DomainController', 'show', $domainRoute) ?>
        </div>
    </form>
<?php elseif ($view === 'remove'): ?>
    <p><?= $this->text->e(t('Remove %s from the watchlist?', $domain['name'])) ?></p>
    <form method="post" action="<?= $this->url->href('DomainController', 'remove', $domainRoute) ?>">
        <?= $this->form->csrf() ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-red"><?= t('Remove') ?></button>
            <?= $this->url->link(t('Cancel'), 'DomainController', 'show', $domainRoute) ?>
        </div>
    </form>
<?php else: ?>
    <?php
    $fields = [
        'registrar' => t('Registrar'),
        'registered_at' => t('Registered on'),
        'registry_expires_at' => t('Registry expiry'),
        'registrar_expires_at' => t('Registrar expiry'),
        'statuses' => t('Registration statuses'),
        'nameservers' => t('Nameservers'),
    ];
    $dates = ['registered_at', 'registry_expires_at', 'registrar_expires_at'];
    $format = static function (string $field, mixed $value) use ($dates): string {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }
        if (is_array($value)) {
            return implode(', ', $value);
        }
        return in_array($field, $dates, true) ? gmdate('Y-m-d', (int) $value) : (string) $value;
    };
    $status = match ($domain['status']) {
        'pending' => t('Pending'),
        'ok' => t('OK'),
        'not_found' => t('Not registered'),
        'unsupported' => t('Unsupported'),
        'error' => t('Error'),
        default => $domain['status'],
    };
    ?>

    <h3><?= t('Registration') ?></h3>
    <dl>
        <dt><?= t('Watchlist status') ?></dt>
        <dd><?= $this->text->e($status) ?></dd>
        <?php foreach ($fields as $field => $label): ?>
            <?php $value = in_array($field, ['statuses', 'nameservers'], true)
                ? json_decode($domain[$field], true)
                : $domain[$field]; ?>
            <dt><?= $this->text->e($label) ?></dt>
            <dd><?= $this->text->e($format($field, $value)) ?></dd>
        <?php endforeach ?>
        <dt><?= t('Last checked') ?></dt>
        <dd><?= $domain['last_checked_at'] === null ? t('Never') : $this->dt->datetime($domain['last_checked_at']) ?></dd>
        <dt><?= t('Last successful refresh') ?></dt>
        <dd>
            <?= $domain['last_success_at'] === null ? t('Never') : $this->dt->datetime($domain['last_success_at']) ?>
            <?php if ($stale): ?>
                <span class="deed-badge deed-stale"><?= t('Stale') ?></span>
                <?= t('No successful refresh in over 3 days.') ?>
            <?php endif ?>
        </dd>
        <?php foreach ($sources as $label => $url): ?>
            <dt><?= $this->text->e($label) ?></dt>
            <dd><a href="<?= $this->text->e($url) ?>"><?= $this->text->e($url) ?></a></dd>
        <?php endforeach ?>
        <dt><?= t('Renewal task') ?></dt>
        <dd>
            <?php if ($renewal !== null): ?>
                <?= $this->url->link($this->text->e($renewal['title']), 'TaskViewController', 'show', ['task_id' => $renewal['id']]) ?>
            <?php elseif (!$hasRenewalProject): ?>
                <?= t('No project for renewals.') ?>
            <?php else: ?>
                <?= t('No open renewal task.') ?>
            <?php endif ?>
        </dd>
    </dl>

    <h3><?= t('DNS records') ?></h3>
    <p>
        <a href="<?= $this->url->href('DomainController', 'dns', $domainRoute) ?>"
           data-deed-dns aria-controls="deed-dns-results"><?= t('Load DNS records') ?></a>
    </p>
    <div id="deed-dns-results" aria-live="polite" data-deed-dns-error="<?= $this->text->e(t('Could not load DNS records.')) ?>"></div>

    <h3><?= t('Change history') ?></h3>
    <?php if ($history === []): ?>
        <p><?= t('No registration history yet.') ?></p>
    <?php else: ?>
        <ol>
            <?php foreach ($history as $entry): ?>
                <li>
                    <?= $this->dt->datetime($entry['observed_at']) ?>
                    <?php if ($entry['changes'] === []): ?>
                        <?= t('First observation') ?>
                    <?php else: ?>
                        <table class="table-striped">
                            <thead>
                                <tr>
                                    <th scope="col"><?= t('Field') ?></th>
                                    <th scope="col"><?= t('Before') ?></th>
                                    <th scope="col"><?= t('After') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entry['changes'] as $field => $change): ?>
                                    <tr>
                                        <th scope="row"><?= $this->text->e($fields[$field] ?? $field) ?></th>
                                        <td><?= $this->text->e($format($field, $change['before'])) ?></td>
                                        <td><?= $this->text->e($format($field, $change['after'])) ?></td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ol>
    <?php endif ?>
<?php endif ?>
