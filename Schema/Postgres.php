<?php

namespace Kanboard\Plugin\Deed\Schema;

use PDO;

const VERSION = 1;

function version_1(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE deed_domains (
            id SERIAL PRIMARY KEY,
            name VARCHAR(253) NOT NULL UNIQUE,
            project_id INTEGER NOT NULL DEFAULT 0,
            assignee_id INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL,
            created_at BIGINT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            registrar TEXT,
            registered_at BIGINT,
            registry_expires_at BIGINT,
            registrar_expires_at BIGINT,
            statuses TEXT NOT NULL DEFAULT '[]',
            nameservers TEXT NOT NULL DEFAULT '[]',
            rdap_url TEXT,
            last_checked_at BIGINT,
            last_success_at BIGINT,
            next_check_at BIGINT NOT NULL DEFAULT 0,
            fail_count INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            fingerprint TEXT
        )
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE deed_snapshots (
            id SERIAL PRIMARY KEY,
            domain_id INTEGER NOT NULL,
            observed_at BIGINT NOT NULL,
            data TEXT NOT NULL
        )
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE deed_renewals (
            id SERIAL PRIMARY KEY,
            domain_id INTEGER NOT NULL,
            cycle_expires_at BIGINT NOT NULL,
            task_id INTEGER NOT NULL,
            thresholds_sent TEXT NOT NULL DEFAULT '[]',
            closed_at BIGINT,
            UNIQUE (domain_id, cycle_expires_at)
        )
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE deed_cache (
            cache_key VARCHAR(255) PRIMARY KEY,
            url TEXT NOT NULL,
            body TEXT NOT NULL,
            etag TEXT,
            last_modified TEXT,
            fetched_at BIGINT NOT NULL,
            expires_at BIGINT NOT NULL,
            http_status INTEGER NOT NULL
        )
        SQL);
}
