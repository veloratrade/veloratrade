<?php

declare(strict_types=1);

namespace Velora\Trades;

use PDO;
use PDOStatement;
use Velora\Core\Database;
use Velora\Core\Exceptions\NotFoundException;

/**
 * Data access for trades (+ trade_exits). Prepared statements only.
 * Ownership scoping is enforced in every query (v1.5 security spec, wired now).
 */
final class TradeRepository
{
    private const PUBLIC_COLUMNS = '
        id, user_id, account_id, external_deal_id, symbol, direction, entry_price, exit_price,
        volume, contract_size, commission, swap, profit_loss, r_multiple, stop_loss, take_profit,
        open_time, close_time, strategy_tag, emotional_score, notes, source, created_at, updated_at
    ';

    public function findOwned(int $id, int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM trades
             WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function requireOwned(int $id, int $userId): array
    {
        $trade = $this->findOwned($id, $userId);
        if ($trade === null) {
            throw new NotFoundException('Trade not found.');
        }
        return $trade;
    }

    /**
     * @param array<string,mixed> $filters ['user_id', 'symbol'?, 'direction'?, 'from'?, 'to'?, 'q'?]
     * @param array{limit:int, offset:int, order?:string} $page
     * @return array{items: array<int,array>, total: int}
     */
    public function search(array $filters, array $page): array
    {
        $where = ['t.user_id = :user_id'];
        $params = ['user_id' => $filters['user_id']];

        if (!empty($filters['symbol'])) {
            $where[] = 't.symbol = :symbol';
            $params['symbol'] = $filters['symbol'];
        }
        if (!empty($filters['direction'])) {
            $where[] = 't.direction = :direction';
            $params['direction'] = $filters['direction'];
        }
        if (!empty($filters['from'])) {
            $where[] = 't.close_time >= :from';
            $params['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 't.close_time <= :to';
            $params['to'] = $filters['to'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(t.symbol LIKE :q OR t.strategy_tag LIKE :q OR t.notes LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $orderSql = match ($page['order'] ?? 'close_time') {
            'open_time' => 't.open_time DESC',
            'profit_loss' => 't.profit_loss DESC',
            default => 't.close_time DESC',
        };

        $countStmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM trades t WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = Database::connection()->prepare(
            "SELECT " . self::PUBLIC_COLUMNS . " FROM trades t
             WHERE {$whereSql}
             ORDER BY {$orderSql}
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit', $page['limit'], PDO::PARAM_INT);
        $stmt->bindValue(':offset', $page['offset'], PDO::PARAM_INT);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * Insert trade row. All monetary values pass through as strings.
     *
     * @return int new trade id
     */
    public function create(array $trade): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO trades
                (user_id, account_id, symbol, direction, entry_price, exit_price, volume, contract_size,
                 commission, swap, profit_loss, r_multiple, stop_loss, take_profit,
                 open_time, close_time, strategy_tag, emotional_score, notes, source)
             VALUES
                (:user_id, :account_id, :symbol, :direction, :entry_price, :exit_price, :volume, :contract_size,
                 :commission, :swap, :profit_loss, :r_multiple, :stop_loss, :take_profit,
                 :open_time, :close_time, :strategy_tag, :emotional_score, :notes, :source)'
        );

        $stmt->bindValue(':user_id', $trade['user_id'], PDO::PARAM_INT);
        self::bindNullable($stmt, ':account_id', $trade['account_id'], PDO::PARAM_INT);
        $stmt->bindValue(':symbol', $trade['symbol']);
        $stmt->bindValue(':direction', $trade['direction']);
        $stmt->bindValue(':entry_price', $trade['entry_price']);
        $stmt->bindValue(':exit_price', $trade['exit_price']);
        $stmt->bindValue(':volume', $trade['volume']);
        $stmt->bindValue(':contract_size', $trade['contract_size']);
        $stmt->bindValue(':commission', $trade['commission']);
        $stmt->bindValue(':swap', $trade['swap']);
        $stmt->bindValue(':profit_loss', $trade['profit_loss']);
        self::bindNullable($stmt, ':r_multiple', $trade['r_multiple']);
        self::bindNullable($stmt, ':stop_loss', $trade['stop_loss']);
        self::bindNullable($stmt, ':take_profit', $trade['take_profit']);
        $stmt->bindValue(':open_time', $trade['open_time']);
        $stmt->bindValue(':close_time', $trade['close_time']);
        self::bindNullable($stmt, ':strategy_tag', $trade['strategy_tag']);
        self::bindNullable($stmt, ':emotional_score', $trade['emotional_score'], PDO::PARAM_INT);
        self::bindNullable($stmt, ':notes', $trade['notes']);
        $stmt->bindValue(':source', $trade['source']);

        $stmt->execute();
        return (int) Database::connection()->lastInsertId();
    }

    public function update(int $id, array $trade): void
    {
        Database::transaction(function () use ($id, $trade): void {
            $pdo = Database::connection();
            // Serialize parent-volume updates with partial-exit creation.
            $lockSuffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $lock = $pdo->prepare(
                'SELECT id FROM trades WHERE id = :id AND user_id = :user_id' . $lockSuffix
            );
            $lock->execute(['id' => $id, 'user_id' => $trade['user_id']]);
            if ($lock->fetchColumn() === false) {
                throw new NotFoundException('Trade not found.');
            }

            $aggregateStmt = $pdo->prepare(
                'SELECT COALESCE(SUM(volume), 0) AS exit_volume, MIN(exited_at) AS first_exit, MAX(exited_at) AS last_exit
                 FROM trade_exits WHERE trade_id = :id'
            );
            $aggregateStmt->execute(['id' => $id]);
            $aggregate = $aggregateStmt->fetch(PDO::FETCH_ASSOC);
            if (bccomp((string) $aggregate['exit_volume'], (string) $trade['volume'], 8) > 0) {
                throw new \Velora\Core\Exceptions\ValidationException(
                    'Trade volume cannot be lower than its recorded exit volume.',
                    ['volume' => ['code' => 'EXIT_VOLUME_EXCEEDED', 'messageKey' => 'errors.validation.range', 'params' => []]],
                );
            }
            if (($aggregate['first_exit'] !== null && $aggregate['first_exit'] < $trade['open_time'])
                || ($aggregate['last_exit'] !== null && $aggregate['last_exit'] > $trade['close_time'])) {
                throw new \Velora\Core\Exceptions\ValidationException(
                    'Trade times must contain every recorded exit.',
                    ['closeTime' => ['code' => 'INVALID_CHRONOLOGY', 'messageKey' => 'errors.validation.datetime', 'params' => []]],
                );
            }

            $stmt = $pdo->prepare(
                'UPDATE trades SET
                    symbol = :symbol, direction = :direction,
                    entry_price = :entry_price, exit_price = :exit_price, volume = :volume,
                    contract_size = :contract_size,
                    commission = :commission, swap = :swap,
                    profit_loss = :profit_loss, r_multiple = :r_multiple,
                    stop_loss = :stop_loss, take_profit = :take_profit,
                    open_time = :open_time, close_time = :close_time,
                    strategy_tag = :strategy_tag, emotional_score = :emotional_score,
                    notes = :notes
                 WHERE id = :id AND user_id = :user_id'
            );

            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $trade['user_id'], PDO::PARAM_INT);
            $stmt->bindValue(':symbol', $trade['symbol']);
            $stmt->bindValue(':direction', $trade['direction']);
            $stmt->bindValue(':entry_price', $trade['entry_price']);
            $stmt->bindValue(':exit_price', $trade['exit_price']);
            $stmt->bindValue(':volume', $trade['volume']);
            $stmt->bindValue(':contract_size', $trade['contract_size']);
            $stmt->bindValue(':commission', $trade['commission']);
            $stmt->bindValue(':swap', $trade['swap']);
            $stmt->bindValue(':profit_loss', $trade['profit_loss']);
            self::bindNullable($stmt, ':r_multiple', $trade['r_multiple']);
            self::bindNullable($stmt, ':stop_loss', $trade['stop_loss']);
            self::bindNullable($stmt, ':take_profit', $trade['take_profit']);
            $stmt->bindValue(':open_time', $trade['open_time']);
            $stmt->bindValue(':close_time', $trade['close_time']);
            self::bindNullable($stmt, ':strategy_tag', $trade['strategy_tag']);
            self::bindNullable($stmt, ':emotional_score', $trade['emotional_score'], PDO::PARAM_INT);
            self::bindNullable($stmt, ':notes', $trade['notes']);
            $stmt->execute();

            // Parent edits must not leave stored partial-exit PnL inconsistent.
            $exits = $pdo->prepare('SELECT id, exit_price, volume FROM trade_exits WHERE trade_id = :id');
            $exits->execute(['id' => $id]);
            $writePnl = $pdo->prepare('UPDATE trade_exits SET pnl = :pnl WHERE id = :id');
            foreach ($exits->fetchAll(PDO::FETCH_ASSOC) as $exit) {
                $ratio = bcdiv((string) $exit['volume'], (string) $trade['volume'], 8);
                $calculated = PnlCalculator::calculate(
                    (string) $trade['entry_price'],
                    (string) $exit['exit_price'],
                    (string) $exit['volume'],
                    (string) $trade['direction'],
                    bcmul((string) $trade['commission'], $ratio, 8),
                    bcmul((string) $trade['swap'], $ratio, 8),
                    null,
                    (string) $trade['contract_size'],
                );
                $writePnl->execute(['pnl' => $calculated['net_pnl'], 'id' => $exit['id']]);
            }
        });
    }

    public function delete(int $id, int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM trades WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        if ($stmt->rowCount() === 0) {
            throw new NotFoundException('Trade not found.');
        }
    }

    /** Distinct symbols owned by the user (for filter dropdowns). */
    public function symbols(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT symbol FROM trades WHERE user_id = :user_id ORDER BY symbol'
        );
        $stmt->execute(['user_id' => $userId]);
        return array_map(static fn (array $r) => $r['symbol'], $stmt->fetchAll());
    }

    public function verifyAccountOwnership(int $accountId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM trading_accounts WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['id' => $accountId, 'user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    public function countTradesForUser(int $userId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM trades WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    private static function bindNullable(PDOStatement $stmt, string $param, mixed $value, int $type = PDO::PARAM_STR): void
    {
        if ($value === null || $value === '') {
            $stmt->bindValue($param, null, PDO::PARAM_NULL);
            return;
        }
        $stmt->bindValue($param, $value, $type);
    }
}
