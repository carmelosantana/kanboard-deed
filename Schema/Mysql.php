<?php

namespace Kanboard\Plugin\Deed\Schema;

use PDO;

const VERSION = 1;

function version_1(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE deed_domains (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            project_id INT NOT NULL DEFAULT 0,
            assignee_id INT NOT NULL DEFAULT 0,
            created_by INT NOT NULL,
            created_at BIGINT NOT NULL,
            status TEXT NOT NULL DEFAULT ('pending'),
            registrar TEXT,
            registered_at BIGINT,
            registry_expires_at BIGINT,
            registrar_expires_at BIGINT,
            statuses TEXT NOT NULL DEFAULT ('[]'),
            nameservers TEXT NOT NULL DEFAULT ('[]'),
            rdap_url TEXT,
            last_checked_at BIGINT,
            last_success_at BIGINT,
            next_check_at BIGINT NOT NULL DEFAULT 0,
            fail_count INT NOT NULL DEFAULT 0,
            last_error TEXT,
            fingerprint TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE deed_snapshots (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            domain_id INT NOT NULL,
            observed_at BIGINT NOT NULL,
            data TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE deed_renewals (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            domain_id INT NOT NULL,
            cycle_expires_at BIGINT NOT NULL,
            task_id INT NOT NULL,
            thresholds_sent TEXT NOT NULL DEFAULT ('[]'),
            closed_at BIGINT,
            UNIQUE (domain_id, cycle_expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE deed_cache (
            cache_key VARCHAR(255) COLLATE utf8mb4_bin PRIMARY KEY,
            url TEXT NOT NULL,
            body MEDIUMTEXT NOT NULL,
            etag TEXT,
            last_modified TEXT,
            fetched_at BIGINT NOT NULL,
            expires_at BIGINT NOT NULL,
            http_status INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
}
