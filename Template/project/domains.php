<div class="page-header">
    <h2><?= t('Domains') ?></h2>
</div>

<?php if ($domains === []): ?>
    <p><?= t('No domains are linked to this project.') ?></p>
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
                </tr>
            </thead>
            <tbody>
                <?php foreach ($domains as $domain): ?>
                    <tr>
                        <th scope="row"><?= $this->text->e($domain['name']) ?></th>
                        <td><?= $this->text->e($domain['registrar'] ?? '—') ?></td>
                        <td><?= $domain['expiry'] === null ? '—' : gmdate('Y-m-d', $domain['expiry']) ?></td>
                        <td><?= $this->text->e($statuses[$domain['status']] ?? $domain['status']) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
