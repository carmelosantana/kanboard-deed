<p><?= t('DNS TTL is not registration expiry.') ?></p>
<?php if ($groups === []): ?>
    <p><?= t('No DNS records were returned.') ?></p>
<?php else: ?>
    <?php foreach ($groups as $type => $records): ?>
        <h4><?= $this->text->e($type) ?></h4>
        <table class="table-striped">
            <thead>
                <tr>
                    <th scope="col"><?= t('Name') ?></th>
                    <th scope="col"><?= t('Value') ?></th>
                    <th scope="col"><?= t('TTL (seconds)') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <?php $value = match ($type) {
                        'A' => $record['ip'],
                        'AAAA' => $record['ipv6'],
                        'MX' => $record['pri'].' '.$record['target'],
                        'NS', 'CNAME' => $record['target'],
                        'TXT' => $record['txt'],
                        'SOA' => implode(' ', [
                            $record['mname'], $record['rname'], $record['serial'], $record['refresh'],
                            $record['retry'], $record['expire'], $record['minimum-ttl'],
                        ]),
                    }; ?>
                    <tr>
                        <td><?= $this->text->e($record['host']) ?></td>
                        <td><?= $this->text->e($value) ?></td>
                        <td><?= (int) $record['ttl'] ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endforeach ?>
<?php endif ?>
