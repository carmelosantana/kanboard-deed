<?php

namespace Kanboard\Plugin\Deed\Schema;

use PDO;

const VERSION = 1;

function version_1(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE deed_domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            project_id INTEGER NOT NULL DEFAULT 0,
            assignee_id INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            registrar TEXT,
            registered_at INTEGER,
            registry_expires_at INTEGER,
            registrar_expires_at INTEGER,
            statuses TEXT NOT NULL DEFAULT '[]',
            nameservers TEXT NOT NULL DEFAULT '[]',
            rdap_url TEXT,
            last_checked_at INTEGER,
            last_success_at INTEGER,
            next_check_at INTEGER NOT NULL DEFAULT 0,
            fail_count INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            fingerprint TEXT
        )
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE deed_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain_id INTEGER NOT NULL,
            observed_at INTEGER NOT NULL,
            data TEXT NOT NULL
        )
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE deed_renewals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain_id INTEGER NOT NULL,
            cycle_expires_at INTEGER NOT NULL,
            task_id INTEGER NOT NULL,
            thresholds_sent TEXT NOT NULL DEFAULT '[]',
            closed_at INTEGER,
            UNIQUE (domain_id, cycle_expires_at)
        )
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE deed_cache (
            cache_key TEXT NOT NULL PRIMARY KEY,
            url TEXT NOT NULL,
            body TEXT NOT NULL,
            etag TEXT,
            last_modified TEXT,
            fetched_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            http_status INTEGER NOT NULL
        )
        SQL);
}
