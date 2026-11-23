<div class="page-header">
    <h2><?= t('Domains') ?></h2>
    <ul>
        <li><?= $this->url->icon('plus', t('Add domain'), 'DomainController', 'add', ['plugin' => 'Deed']) ?></li>
        <li><?= $this->url->icon('list', t('Bulk add'), 'DomainController', 'bulk', ['plugin' => 'Deed']) ?></li>
    </ul>
</div>

<?php if ($message !== null): ?>
    <p class="alert alert-info" role="status"><?= $this->text->e($message) ?></p>
<?php endif ?>

<?php if ($domains === []): ?>
    <p><?= t('No domains are on the watchlist.') ?></p>
<?php else: ?>
    <?php $statuses = [
        'pending' => t('Pending'),
        'ok' => t('OK'),
        'not_found' => t('Not found'),
        'unsupported' => t('Unsupported'),
        'error' => t('Error'),
    ]; ?>
    <div class="table-list">
        <table class="table-striped deed-domains">
            <thead>
                <tr>
                    <th scope="col"><?= t('Domain') ?></th>
                    <th scope="col"><?= t('Registrar') ?></th>
                    <th scope="col"><?= t('Expires') ?></th>
                    <th scope="col"><?= t('Status') ?></th>
                    <th scope="col"><?= t('Project') ?></th>
                    <th scope="col"><?= t('Last checked') ?></th>
                    <th scope="col"><?= t('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($domains as $domain): ?>
                    <tr>
                        <th scope="row"><?= $this->url->link($this->text->e($domain['name']), 'DomainController', 'show', ['plugin' => 'Deed', 'domain_id' => $domain['id']]) ?></th>
                        <td><?= $this->text->e($domain['registrar'] ?? '—') ?></td>
                        <td>
                            <?php if ($domain['expiry'] === null): ?>
                                —
                            <?php else: ?>
                                <?= gmdate('Y-m-d', $domain['expiry']) ?>
                                <?php $days = $domain['days_left']; ?>
                                <?php $badge = $days <= 7 ? ' deed-badge--red' : ($days <= 30 ? ' deed-badge--amber' : ''); ?>
                                <span class="deed-badge<?= $badge ?>"><?= $this->text->e(match (true) {
                                    $days < 0 => t('Expired'),
                                    $days === 0 => t('Expires today'),
                                    $days === 1 => t('1 day left'),
                                    default => t('%d days left', $days),
                                }) ?></span>
                            <?php endif ?>
                        </td>
                        <td><?= $this->text->e($statuses[$domain['status']] ?? $domain['status']) ?></td>
                        <td><?= $this->text->e($projects[$domain['project_id']] ?? '—') ?></td>
                        <td>
                            <?= $domain['last_checked_at'] === null
                                ? t('Never')
                                : $this->dt->datetime($domain['last_checked_at']) ?>
                            <?php if ($domain['stale']): ?>
                                <span class="deed-badge deed-stale"
                                      title="<?= $this->text->e(t('No successful refresh in over 3 days.')) ?>"><?= t('Stale') ?></span>
                            <?php endif ?>
                        </td>
                        <td>
                            <form method="post" action="<?= $this->url->href('DomainController', 'refresh', ['plugin' => 'Deed']) ?>" data-deed-refresh>
                                <?= $this->form->csrf() ?>
                                <input type="hidden" name="domain_id" value="<?= (int) $domain['id'] ?>">
                                <button type="submit" class="btn"
                                        aria-label="<?= $this->text->e(t('Refresh %s now', $domain['name'])) ?>"><?= t('Refresh now') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
