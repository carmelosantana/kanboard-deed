<?php

namespace Kanboard\Plugin\Deed\Test;

use KanboardTests\units\Base;
use PDO;
use PDOException;
use function Kanboard\Plugin\Deed\Schema\version_1;
use const Kanboard\Plugin\Deed\Schema\VERSION;

require_once 'tests/units/Base.php';
require_once __DIR__.'/ReleasesConnection.php';

class SchemaTest extends Base
{
    use ReleasesConnection;

    public function testVersion1Tables(): void
    {
        $dialect = ucfirst(DB_DRIVER);
        require_once __DIR__.'/../Schema/'.$dialect.'.php';
        $this->assertSame(1, VERSION);

        $pdo = $this->container['db']->getConnection();
        version_1($pdo);

        $pdo->exec("INSERT INTO deed_domains (id, name, created_by, created_at) VALUES (1, 'example.com', 2, 1700000000)");
        $domain = $pdo->query('SELECT * FROM deed_domains WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('example.com', $domain['name']);
        $this->assertSame('pending', $domain['status']);
        $this->assertSame(0, (int) $domain['project_id']);
        $this->assertSame(0, (int) $domain['assignee_id']);
        $this->assertNull($domain['registry_expires_at']);
        $this->assertNull($domain['registrar_expires_at']);

        $pdo->exec("INSERT INTO deed_snapshots (domain_id, observed_at, data) VALUES (1, 1700000001, '{}')");
        $this->assertSame('{}', $pdo->query('SELECT data FROM deed_snapshots WHERE domain_id = 1')->fetchColumn());

        $pdo->exec("INSERT INTO deed_renewals (domain_id, cycle_expires_at, task_id, thresholds_sent) VALUES (1, 1800000000, 42, '[30]')");
        $renewal = $pdo->query('SELECT * FROM deed_renewals WHERE domain_id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(42, (int) $renewal['task_id']);
        $this->assertSame('[30]', $renewal['thresholds_sent']);

        $pdo->exec("INSERT INTO deed_cache (cache_key, url, body, fetched_at, expires_at, http_status)
            VALUES ('iana-dns', 'https://data.iana.org/rdap/dns.json', '{}', 1700000000, 1700086400, 200)");
        $cache = $pdo->query("SELECT * FROM deed_cache WHERE cache_key = 'iana-dns'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('{}', $cache['body']);
        $this->assertSame(200, (int) $cache['http_status']);

        $this->assertDuplicateRejected($pdo,
            "INSERT INTO deed_renewals (domain_id, cycle_expires_at, task_id, thresholds_sent) VALUES (1, 1800000000, 43, '[30]')");
        $this->assertDuplicateRejected($pdo,
            "INSERT INTO deed_domains (id, name, created_by, created_at) VALUES (2, 'example.com', 2, 1700000000)");
    }

    private function assertDuplicateRejected(PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
        } catch (PDOException $exception) {
            $this->assertStringStartsWith('23', (string) $exception->getCode());
            return;
        }

        $this->fail('The duplicate insert succeeded.');
    }
}
