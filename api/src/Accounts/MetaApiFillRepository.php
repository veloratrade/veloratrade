<?php

declare(strict_types=1);

namespace Velora\Accounts;

use PDO;
use Velora\Core\Database;

/**
 * Phase 5 (Objective A) — durable MetaApi FILL/DEAL ledger.
 *
 * Every normalized deal (historical sync or realtime webhook) is persisted
 * here exactly once, keyed by (account_id, external_deal_id). This makes IN
 * and OUT fills pairable ACROSS separate webhook events, workers, retries and
 * restarts, and lets historical sync + realtime delivery converge on the same
 * fill set without duplication.
 *
 * Canonical time rule (unchanged from the rest of the architecture): only the
 * offset-explicit MetaApi `time` (resolved to UTC by the caller) populates
 * occurred_at_utc / time_status='resolved'. Naive brokerTime is stored as
 * evidence (broker_time_text) and never becomes an instant.
 *
 * Prepared statements only; no raw external text in DDL/identifiers.
 */
final class MetaApiFillRepository
{
    public const STATE_RECEIVED = 'received';
    public const STATE_AGGREGATED = 'aggregated';
    public const STATE_SKIPPED = 'skipped';
    public const STATE_REJECTED = 'rejected';

    /**
     * Insert one normalized deal fill idempotently. Returns
     * ['inserted' => bool (true only if a new ledger row was created), 'id' => int].
     *
     * @param array<string,mixed> $deal normalized deal (MetaApiService shape)
     */
    public function recordFill(PDO $pdo, int $accountId, int $userId, array $deal, string $source, ?string $eventRef = null): array
    {
        $sql = $this->driver($pdo) === 'sqlite'
            ? 'INSERT OR IGNORE INTO metaapi_fills
               (account_id,user_id,external_deal_id,position_id,order_id,entry_type,direction,symbol,
                volume,price,profit,commission,swap,occurred_at_utc,time_status,raw_time_text,broker_time_text,
                ingestion_source,event_ref,processing_state,created_at,updated_at)
               VALUES
               (:account_id,:user_id,:external,:position,:order,:entry,:direction,:symbol,
                :volume,:price,:profit,:commission,:swap,:occurred,:time_status,:raw_time,:broker_time,
                :source,:event_ref,:state,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)'
            : 'INSERT IGNORE INTO metaapi_fills
               (account_id,user_id,external_deal_id,position_id,order_id,entry_type,direction,symbol,
                volume,price,profit,commission,swap,occurred_at_utc,time_status,raw_time_text,broker_time_text,
                ingestion_source,event_ref,processing_state,created_at,updated_at)
               VALUES
               (:account_id,:user_id,:external,:position,:order,:entry,:direction,:symbol,
                :volume,:price,:profit,:commission,:swap,:occurred,:time_status,:raw_time,:broker_time,
                :source,:event_ref,:state,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)';

        $stmt = $pdo->prepare($sql);
        $resolved = is_string($deal['time_utc'] ?? null) && $deal['time_utc'] !== '';
        $stmt->execute([
            'account_id' => $accountId,
            'user_id' => $userId,
            'external' => (string) ($deal['external_deal_id'] ?? ''),
            'position' => $deal['position_id'] ?? null,
            'order' => $deal['order_id'] ?? null,
            'entry' => $deal['entry_type'] ?? null,
            'direction' => $deal['direction'] ?? null,
            'symbol' => $deal['symbol'] ?? null,
            'volume' => $deal['volume'] ?? null,
            'price' => $deal['price'] ?? null,
            'profit' => $deal['profit'] ?? null,
            'commission' => $deal['commission'] ?? null,
            'swap' => $deal['swap'] ?? null,
            'occurred' => $resolved ? $deal['time_utc'] : null,
            'time_status' => $resolved ? 'resolved' : 'unresolved',
            'raw_time' => $deal['time_raw'] ?? null,
            'broker_time' => $deal['broker_time'] ?? null,
            'source' => $source,
            'event_ref' => $eventRef,
            'state' => self::STATE_RECEIVED,
        ]);
        $inserted = $stmt->rowCount() === 1;

        $id = (int) $pdo->lastInsertId();
        if (!$inserted) {
            $find = $pdo->prepare('SELECT id FROM metaapi_fills WHERE account_id=:a AND external_deal_id=:e LIMIT 1');
            $find->execute(['a' => $accountId, 'e' => (string) ($deal['external_deal_id'] ?? '')]);
            $existing = $find->fetchColumn();
            if ($existing !== false) {
                $id = (int) $existing;
            }
        }
        return ['inserted' => $inserted, 'id' => $id];
    }

    /**
     * Fetch all trade fills (entry_type in/out) for an account as assembler
     * input rows. Only normalized trade fills are returned (rejected/balance
     * deals are never record()ed by the service).
     *
     * @return array<int,array<string,mixed>>
     */
    public function fillsForReconciliation(PDO $pdo, int $accountId): array
    {
        $stmt = $pdo->prepare(
            "SELECT external_deal_id, position_id, order_id, entry_type, direction, symbol,
                    volume, price, profit, commission, swap,
                    occurred_at_utc AS time_utc, raw_time_text AS time_raw, broker_time_text AS broker_time
             FROM metaapi_fills
             WHERE account_id = :account_id
               AND entry_type IN ('in','out')
             ORDER BY occurred_at_utc ASC, id ASC"
        );
        $stmt->execute(['account_id' => $accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $r): array {
            // assembler keys on time_utc string|null; normalize DB null.
            $r['time_utc'] = $r['time_utc'] !== null ? (string) $r['time_utc'] : null;
            $r['time_raw'] = $r['time_raw'] !== null ? (string) $r['time_raw'] : null;
            $r['broker_time'] = $r['broker_time'] !== null ? (string) $r['broker_time'] : null;
            return $r;
        }, $rows);
    }

    /**
     * Position ids that have at least one received/skipped (not yet aggregated)
     * trade fill — reconciliation candidates.
     *
     * @return array<int,string>
     */
    public function pendingPositionIds(PDO $pdo, int $accountId): array
    {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT position_id FROM metaapi_fills
             WHERE account_id = :account_id AND position_id IS NOT NULL
               AND processing_state = :state"
        );
        $stmt->execute(['account_id' => $accountId, 'state' => self::STATE_RECEIVED]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** All trade fills for one position, as assembler input (any state). */
    public function fillsForPosition(PDO $pdo, int $accountId, string $positionId): array
    {
        $stmt = $pdo->prepare(
            "SELECT external_deal_id, position_id, order_id, entry_type, direction, symbol,
                    volume, price, profit, commission, swap,
                    occurred_at_utc AS time_utc, raw_time_text AS time_raw, broker_time_text AS broker_time
             FROM metaapi_fills
             WHERE account_id = :account_id AND position_id = :position AND entry_type IN ('in','out')
             ORDER BY occurred_at_utc ASC, id ASC"
        );
        $stmt->execute(['account_id' => $accountId, 'position' => $positionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $r): array {
            $r['time_utc'] = $r['time_utc'] !== null ? (string) $r['time_utc'] : null;
            $r['time_raw'] = $r['time_raw'] !== null ? (string) $r['time_raw'] : null;
            $r['broker_time'] = $r['broker_time'] !== null ? (string) $r['broker_time'] : null;
            return $r;
        }, $rows);
    }

    /** Fill ids belonging to a position (for state updates after aggregation). */
    public function fillIdsForPosition(PDO $pdo, int $accountId, string $positionId): array
    {
        $stmt = $pdo->prepare(
            "SELECT id FROM metaapi_fills
             WHERE account_id = :account_id AND position_id = :position AND entry_type IN ('in','out')"
        );
        $stmt->execute(['account_id' => $accountId, 'position' => $positionId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function markAggregated(PDO $pdo, array $fillIds, ?int $tradeId = null): void
    {
        if ($fillIds === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($fillIds), '?'));
        $stmt = $pdo->prepare(
            "UPDATE metaapi_fills SET processing_state = ?, processed_trade_id = ?,
                skip_reason = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id IN ($in)"
        );
        $stmt->execute(array_merge([self::STATE_AGGREGATED, $tradeId], $fillIds));
    }

    public function markSkipped(PDO $pdo, array $fillIds, string $reason): void
    {
        if ($fillIds === []) {
            return;
        }
        $reason = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($reason)) ?? 'skipped', 0, 48);
        $in = implode(',', array_fill(0, count($fillIds), '?'));
        $stmt = $pdo->prepare(
            "UPDATE metaapi_fills SET processing_state = ?, skip_reason = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id IN ($in) AND processing_state = ?"
        );
        $stmt->execute(array_merge([self::STATE_SKIPPED, $reason], $fillIds, [self::STATE_RECEIVED]));
    }

    private function driver(PDO $pdo): string
    {
        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
