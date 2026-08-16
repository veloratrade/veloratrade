<?php

declare(strict_types=1);

namespace Velora\Dashboard;

use PDO;
use Velora\Core\Database;

/**
 * Basic metrics for the v0.1 dashboard summary:
 * win rate, total net PnL, profit factor, average R, best/worst trade,
 * and a daily-aggregated equity curve.
 *
 * All math in string bcmath (CTO checklist #5).
 */
final class MetricsService
{
    public function summary(int $userId): array
    {
        $pdo = Database::connection();

        $agg = $pdo->prepare(
            'SELECT
                COUNT(*)                                              AS trade_count,
                COALESCE(SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END), 0) AS wins,
                COALESCE(SUM(CASE WHEN profit_loss < 0 THEN 1 ELSE 0 END), 0) AS losses,
                COALESCE(SUM(CASE WHEN profit_loss = 0 THEN 1 ELSE 0 END), 0) AS breakeven,
                COALESCE(SUM(profit_loss), 0)                         AS total_pnl,
                COALESCE(SUM(CASE WHEN profit_loss > 0 THEN profit_loss ELSE 0 END), 0) AS gross_profit,
                COALESCE(SUM(CASE WHEN profit_loss < 0 THEN ABS(profit_loss) ELSE 0 END), 0) AS gross_loss,
                COALESCE(MAX(profit_loss), 0)                         AS best_trade,
                COALESCE(MIN(profit_loss), 0)                         AS worst_trade,
                COALESCE(AVG(CASE WHEN r_multiple IS NOT NULL THEN r_multiple END), 0) AS avg_r
             FROM trades
             WHERE user_id = :user_id'
        );
        $agg->execute(['user_id' => $userId]);
        $row = $agg->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            $row = [];
        }

        $count = (int) ($row['trade_count'] ?? 0);
        $wins = (int) ($row['wins'] ?? 0);
        $losses = (int) ($row['losses'] ?? 0);

        $decided = $wins + $losses;
        $winRate = $decided > 0 ? bcdiv((string) $wins, (string) $decided, 6) : '0';
        $profitFactor = bccomp((string) $row['gross_loss'], '0', 8) > 0
            ? bcdiv((string) $row['gross_profit'], (string) $row['gross_loss'], 4)
            : (bccomp((string) $row['gross_profit'], '0', 8) > 0 ? null : '0'); // null = بی‌نهایت (بدون ضرر)

        return [
            'tradeCount' => $count,
            'wins' => $wins,
            'losses' => $losses,
            'breakeven' => (int) ($row['breakeven'] ?? 0),
            'winRate' => bcadd($winRate, '0', 4),
            'totalPnl' => bcadd((string) $row['total_pnl'], '0', 2),
            'profitFactor' => $profitFactor,
            'averageR' => bcadd((string) ($row['avg_r'] ?? '0'), '0', 4),
            'bestTrade' => bcadd((string) $row['best_trade'], '0', 2),
            'worstTrade' => bcadd((string) $row['worst_trade'], '0', 2),
        ];
    }

    /** Daily equity curve (cumulative net PnL per day, starting from 0). */
    public function equityCurve(int $userId, int $days = 30): array
    {
        $days = max(7, min(365, $days));
        // The cutoff is computed here and bound as a parameter so the query runs
        // unchanged on MySQL and SQLite. DATE_SUB()/CURDATE() are MySQL-only and
        // made this endpoint fail with a 500 on a SQLite deployment.
        $cutoff = gmdate('Y-m-d', strtotime("-{$days} day"));
        $stmt = Database::connection()->prepare(
            'SELECT DATE(close_time) AS day, SUM(profit_loss) AS day_pnl
             FROM trades
             WHERE user_id = :user_id
               AND close_time IS NOT NULL
               AND close_time >= :cutoff
             GROUP BY DATE(close_time)
             ORDER BY day ASC'
        );
        $stmt->execute(['user_id' => $userId, 'cutoff' => $cutoff]);

        $cumulative = '0';
        $points = [];
        foreach ($stmt->fetchAll() as $row) {
            $cumulative = bcadd($cumulative, (string) $row['day_pnl'], 2);
            $points[] = [
                'date' => $row['day'],
                'pnl' => bcadd((string) $row['day_pnl'], '0', 2),
                'equity' => $cumulative,
            ];
        }
        return $points;
    }

    /**
     * Win rate / profit factor per strategy tag (roadmap v0.5 tags matrix,
     * base available from v0.1).
     */
    public function perStrategy(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT
                NULLIF(strategy_tag, \'\') AS strategy,
                COUNT(*) AS cnt,
                COALESCE(SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END), 0) AS wins,
                COALESCE(SUM(profit_loss), 0) AS pnl
             FROM trades
             WHERE user_id = :user_id
             GROUP BY strategy
             ORDER BY pnl DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $count = (int) $r['cnt'];
            $wins = (int) $r['wins'];
            $rows[] = [
                'strategy' => $r['strategy'],
                'tradeCount' => $count,
                'winRate' => $count > 0 ? bcdiv((string) $wins, (string) $count, 4) : '0',
                'pnl' => bcadd((string) $r['pnl'], '0', 2),
            ];
        }
        return $rows;
    }
}
