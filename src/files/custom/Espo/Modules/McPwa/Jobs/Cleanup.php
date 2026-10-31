<?php

namespace Espo\Modules\McPwa\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Daily housekeeping:
 *  - removes subscriptions that keep failing (`pwaSubscriptionMaxFailures` consecutive failures, 0 = off);
 *  - removes anonymous installation records not seen for `pwaInstallationRetentionDays` days (0 = keep).
 */
class Cleanup implements JobDataLess
{
    public const DEFAULT_MAX_FAILURES = 10;
    public const DEFAULT_RETENTION_DAYS = 365;

    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function run(): void
    {
        $maxFailures = $this->getInt('pwaSubscriptionMaxFailures', self::DEFAULT_MAX_FAILURES);
        $retentionDays = $this->getInt('pwaInstallationRetentionDays', self::DEFAULT_RETENTION_DAYS);

        if ($maxFailures > 0) {
            $this->delete('PwaSubscription', ['failureCount>=' => $maxFailures]);
        }

        if ($retentionDays > 0) {
            $this->delete('PwaInstallation', [
                'lastSeenAt<' => gmdate('Y-m-d H:i:s', time() - $retentionDays * 86400),
            ]);
        }
    }

    private function getInt(string $param, int $default): int
    {
        $value = $this->config->get($param);

        return is_int($value) ? max(0, $value) : $default;
    }

    /**
     * @param array<string, mixed> $where
     */
    private function delete(string $entityType, array $where): void
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->delete()
            ->from($entityType)
            ->where($where)
            ->build();

        $count = $this->entityManager->getQueryExecutor()->execute($query)->rowCount();

        if ($count > 0) {
            $this->log->info("McPwa cleanup: removed $count $entityType record(s).");
        }
    }
}
