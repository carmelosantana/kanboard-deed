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
    private const SLOT_SECONDS = 300;
    private const DEFAULT_BUDGET_SECONDS = 10;

    public function maybeRun(?DateTimeImmutable $now = null): bool
    {
        $timezone = new DateTimeZone($this->configModel->get('application_timezone', 'UTC'));
        $now = ($now ?? new DateTimeImmutable())->setTimezone($timezone);
        if ((int) $now->format('G') < (int) $this->configModel->get('deed_refresh_hour', 6)) {
            return false;
        }

        $timestamp = $now->getTimestamp();
        $lastSweep = $this->configModel->getOption(self::LAST_SWEEP);
        // Honor a legacy date stamp through the day it claimed.
        if ($lastSweep === $now->format('Y-m-d') || (int) $lastSweep > $timestamp - self::SLOT_SECONDS) {
            return false;
        }

        $dueId = $this->db->table('deed_domains')->lte('next_check_at', $timestamp)->findOneColumn('id');
        if ($dueId === false || !$this->claimSlot($lastSweep, $timestamp)) {
            return false;
        }

        $budget = (int) $this->configModel->get('deed_web_budget_seconds', self::DEFAULT_BUDGET_SECONDS);
        $this->deedRefreshService->sweep(null, $budget > 0 ? $budget : self::DEFAULT_BUDGET_SECONDS);
        return true;
    }

    private function claimSlot(string $lastSweep, int $timestamp): bool
    {
        $pdo = $this->db->getConnection();
        $table = ConfigModel::TABLE;
        $parameters = [self::LAST_SWEEP, (string) $timestamp, $timestamp, $lastSweep];
        // Only replace the value used to check eligibility. A concurrent claim changes that value.
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql = "INSERT INTO {$table} (`option`, `value`, changed_on) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        changed_on = IF(COALESCE(`value`, '') = ?, VALUES(changed_on), changed_on),
                        `value` = IF(COALESCE(`value`, '') = ?, VALUES(`value`), `value`)";
            $parameters[] = $lastSweep;
        } else {
            $sql = "INSERT INTO {$table} (\"option\", \"value\", changed_on) VALUES (?, ?, ?)
                    ON CONFLICT (\"option\") DO UPDATE SET
                        \"value\" = excluded.\"value\", changed_on = excluded.changed_on
                    WHERE COALESCE({$table}.\"value\", '') = ?";
        }

        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->rowCount() > 0;
    }
}
