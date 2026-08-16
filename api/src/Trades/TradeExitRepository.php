<?php

declare(strict_types=1);

namespace Velora\Trades;

use PDO;
use Velora\Core\Database;
use Velora\Core\Exceptions\NotFoundException;

/**
 * Data access for trade_exits (partial exits: TP / SL / manual / partial).
 * تمام کوئری‌ها prepared و متعلق به مالک بررسی می‌شود.
 */
final class TradeExitRepository
{
    private const COLUMNS = '
        te.id, te.trade_id, te.exit_type, te.exit_price, te.volume, te.pnl, te.exited_at, te.notes
    ';

    /** لیست خروجی‌های یک معامله (فقط اگر مالک باشد) */
    public function listByTrade(int $tradeId, int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM trade_exits te
             JOIN trades t ON t.id = te.trade_id
             WHERE te.trade_id = :tid AND t.user_id = :uid
             ORDER BY te.exited_at ASC, te.id ASC'
        );
        $stmt->execute(['tid' => $tradeId, 'uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** افزودن خروجی جزئی (مالکیت معامله بررسی می‌شود) */
    public function create(int $tradeId, int $userId, array $data): int
    {
        return Database::transaction(function () use ($tradeId, $userId, $data): int {
            $pdo = Database::connection();
            $lockSuffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $parent = $pdo->prepare(
                'SELECT id, entry_price, volume, direction, contract_size, commission, swap, open_time, close_time
                 FROM trades WHERE id = :id AND user_id = :uid LIMIT 1' . $lockSuffix
            );
            $parent->execute(['id' => $tradeId, 'uid' => $userId]);
            $trade = $parent->fetch(PDO::FETCH_ASSOC);
            if ($trade === false) {
                throw new NotFoundException('Trade not found.');
            }

            if ($data['exitedAt'] < $trade['open_time']
                || ($trade['close_time'] !== null && $data['exitedAt'] > $trade['close_time'])) {
                throw new \Velora\Core\Exceptions\ValidationException(
                    'Exit time must be within the trade lifetime.',
                    ['exitedAt' => ['code' => 'INVALID_CHRONOLOGY', 'messageKey' => 'errors.validation.datetime', 'params' => []]],
                );
            }

            $sum = $pdo->prepare('SELECT COALESCE(SUM(volume), 0) FROM trade_exits WHERE trade_id = :tid');
            $sum->execute(['tid' => $tradeId]);
            $newTotal = bcadd((string) $sum->fetchColumn(), $data['volume'], 8);
            if (bccomp($newTotal, (string) $trade['volume'], 8) > 0) {
                throw new \Velora\Core\Exceptions\ValidationException(
                    'Cumulative exit volume exceeds the trade volume.',
                    ['volume' => ['code' => 'EXIT_VOLUME_EXCEEDED', 'messageKey' => 'errors.validation.range', 'params' => []]],
                );
            }

            // Allocate parent-level costs proportionally and apply the same
            // server-side PnL convention as the main trade.
            $ratio = bcdiv($data['volume'], (string) $trade['volume'], 8);
            $commission = bcmul((string) $trade['commission'], $ratio, 8);
            $swap = bcmul((string) $trade['swap'], $ratio, 8);
            $calculated = PnlCalculator::calculate(
                (string) $trade['entry_price'],
                $data['exitPrice'],
                $data['volume'],
                (string) $trade['direction'],
                $commission,
                $swap,
                null,
                (string) ($trade['contract_size'] ?? '1'),
            );

            $stmt = $pdo->prepare(
                'INSERT INTO trade_exits (trade_id, exit_type, exit_price, volume, pnl, exited_at, notes)
                 VALUES (:tid, :et, :ep, :v, :pnl, :ea, :notes)'
            );
            $stmt->execute([
                'tid' => $tradeId,
                'et' => $data['exitType'],
                'ep' => $data['exitPrice'],
                'v' => $data['volume'],
                'pnl' => $calculated['net_pnl'],
                'ea' => $data['exitedAt'],
                'notes' => $data['notes'] ?? null,
            ]);
            return (int) $pdo->lastInsertId();
        });
    }

    /** حذف خروجی جزئی (مالکیت بررسی می‌شود) */
    public function delete(int $exitId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE te FROM trade_exits te
             JOIN trades t ON t.id = te.trade_id
             WHERE te.id = :eid AND t.user_id = :uid'
        );
        $stmt->execute(['eid' => $exitId, 'uid' => $userId]);
        return $stmt->rowCount() > 0;
    }
}
