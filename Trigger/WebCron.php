<?php

namespace Kanboard\Plugin\Deed\Trigger;

use DateTimeImmutable;
use DateTimeZone;
use Kanboard\Core\Base;
use Kanboard\Model\ConfigModel;
use PDO;

final class WebCron extends Base
{
    private const LAST_SWEEP = 'deed_last_sweep';

    public function maybeRun(?DateTimeImmutable $now = null): bool
    {
        $timezone = new DateTimeZone($this->configModel->get('application_timezone', 'UTC'));
        $now = ($now ?? new DateTimeImmutable())->setTimezone($timezone);
        if ((int) $now->format('G') < (int) $this->configModel->get('deed_refresh_hour', 6)) {
            return false;
        }

        $today = $now->format('Y-m-d');
        if ($this->configModel->getOption(self::LAST_SWEEP) === $today || !$this->claimDay($today, $now->getTimestamp())) {
            return false;
        }

        $this->deedRefreshService->sweep();
        return true;
    }

    private function claimDay(string $today, int $timestamp): bool
    {
        $pdo = $this->db->getConnection();
        $table = ConfigModel::TABLE;
        // The settings key is unique. Only an insert or a change to the date wins the claim.
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql = "INSERT INTO {$table} (`option`, `value`, changed_on) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        changed_on = IF(`value` <=> VALUES(`value`), changed_on, VALUES(changed_on)),
                        `value` = VALUES(`value`)";
        } else {
            $sql = "INSERT INTO {$table} (\"option\", \"value\", changed_on) VALUES (?, ?, ?)
                    ON CONFLICT (\"option\") DO UPDATE SET
                        \"value\" = excluded.\"value\", changed_on = excluded.changed_on
                    WHERE {$table}.\"value\" IS NULL OR {$table}.\"value\" <> excluded.\"value\"";
        }

        $statement = $pdo->prepare($sql);
        $statement->execute([self::LAST_SWEEP, $today, $timestamp]);

        return $statement->rowCount() > 0;
    }
}
