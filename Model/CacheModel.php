<?php

namespace Kanboard\Plugin\Deed\Model;

use PDO;

final class CacheModel
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM deed_cache WHERE cache_key = ?');
        $statement->execute([$key]);
        $entry = $statement->fetch(PDO::FETCH_ASSOC);

        return $entry === false ? null : $entry;
    }

    /** Defer retries only while the stale row read before the fetch is still current. */
    public function deferRetry(array $previous, int $retryAt): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE deed_cache SET expires_at = ?
             WHERE cache_key = ? AND fetched_at = ? AND expires_at = ? AND body = ?'
        );
        $statement->execute([
            $retryAt,
            $previous['cache_key'],
            $previous['fetched_at'],
            $previous['expires_at'],
            $previous['body'],
        ]);
    }

    /** Save only if the row read before the HTTP request is still current. */
    public function save(array $entry, ?array $previous): bool
    {
        if ($previous === null) {
            $conflict = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? 'ON DUPLICATE KEY UPDATE cache_key = deed_cache.cache_key'
                : 'ON CONFLICT (cache_key) DO NOTHING';
            $statement = $this->pdo->prepare(
                'INSERT INTO deed_cache (cache_key, url, body, etag, last_modified, fetched_at, expires_at, http_status)
                 VALUES (:cache_key, :url, :body, :etag, :last_modified, :fetched_at, :expires_at, :http_status) '.$conflict
            );
        } else {
            $bodyAssignment = 'body = :body, ';
            if ($entry['body'] === null) {
                unset($entry['body']);
                $bodyAssignment = '';
            }

            $statement = $this->pdo->prepare(
                'UPDATE deed_cache SET '.$bodyAssignment.'url = :url, etag = :etag, last_modified = :last_modified,
                 fetched_at = :fetched_at, expires_at = :expires_at, http_status = :http_status
                 WHERE cache_key = :cache_key AND fetched_at = :previous_fetch AND expires_at = :previous_expiry
                 AND body = :previous_body'
            );
            $entry['previous_fetch'] = $previous['fetched_at'];
            $entry['previous_expiry'] = $previous['expires_at'];
            $entry['previous_body'] = $previous['body'];
        }

        $statement->execute($entry);

        return $statement->rowCount() > 0;
    }
}
