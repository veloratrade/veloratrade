<?php

declare(strict_types=1);

namespace Velora\AI\Reports;

use Velora\AI\Repositories\AIRepository;

/**
 * Repository for ai_reports — stores weekly/monthly reports.
 * Does NOT query trades directly, receives TradeDataDTO[] from Analysis module.
 * Extends AIRepository for future DB separation.
 */
final class ReportRepository extends AIRepository
{
    private const TABLE = 'ai_reports';

    public function create(ReportDTO $report): int
    {
        try {
            $stmt = $this->connection()->prepare(
                'INSERT INTO ' . self::TABLE . ' (user_id, period_start, period_end, locale, content)
                 VALUES (:user_id, :period_start, :period_end, :locale, :content)'
            );
            $stmt->bindValue(':user_id', $report->userId, \PDO::PARAM_INT);
            $stmt->bindValue(':period_start', $report->periodStart);
            $stmt->bindValue(':period_end', $report->periodEnd);
            $stmt->bindValue(':locale', $report->locale);
            $stmt->bindValue(':content', json_encode($report->content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $stmt->execute();
            return (int) $this->connection()->lastInsertId();
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_REPORTS] create failed: ' . $e->getMessage());
            return 0;
        }
    }

    public function findForPeriod(int $userId, string $periodStart, string $periodEnd, string $locale = 'en'): ?array
    {
        try {
            $stmt = $this->connection()->prepare(
                'SELECT * FROM ' . self::TABLE . '
                 WHERE user_id = :user_id AND period_start = :period_start AND period_end = :period_end AND locale = :locale
                 LIMIT 1'
            );
            $stmt->execute([
                'user_id' => $userId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'locale' => $locale,
            ]);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<int,array>
     */
    public function recentForUser(int $userId, int $limit = 10): array
    {
        try {
            $stmt = $this->connection()->prepare(
                'SELECT * FROM ' . self::TABLE . ' WHERE user_id = :user_id ORDER BY period_start DESC LIMIT :limit'
            );
            $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
