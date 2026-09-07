<?php

declare(strict_types=1);

namespace Velora\Accounts;

use PDO;
use Velora\Core\Database;

/**
 * Data access for trading_accounts and MetaApi bridge state.
 * Public reads intentionally exclude encrypted connection credentials.
 */
final class AccountRepository
{
    private const PUBLIC_COLUMNS =
        'id, user_id, provider, platform, broker, server, mt_login, account_type,
         metaapi_account_id, sync_status, last_synced_at, connected_at, disconnected_at,
         auto_sync_enabled, last_incremental_at, connection_checked_at, consecutive_errors,
         last_error, starting_balance, current_balance, label, account_number_masked,
         currency, leverage, status, balance, equity, timezone, timezone_source,
         created_at, updated_at';

    public function listByUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . '
             FROM trading_accounts WHERE user_id = :user_id ORDER BY created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function searchGlobal(array $filters, array $page): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'sync_status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['platform'])) {
            $where[] = 'platform = :platform';
            $params['platform'] = (string) $filters['platform'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(label LIKE :q OR broker LIKE :q OR server LIKE :q OR mt_login LIKE :q OR account_number_masked LIKE :q)';
            $params['q'] = '%' . (string) $filters['q'] . '%';
        }

        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
        $orderSql = match ($page['order'] ?? 'created_at') {
            'balance' => 'balance DESC, id DESC',
            'last_synced_at' => 'last_synced_at DESC, id DESC',
            default => 'created_at DESC, id DESC',
        };

        $countStmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM trading_accounts WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = Database::connection()->prepare(
            "SELECT " . self::PUBLIC_COLUMNS . "
             FROM trading_accounts
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
     * Phase 4 — real platform-wide sync-status counts (the "platform
     * summary" chips on the frozen Trading Accounts page). One GROUP BY over
     * the small accounts table; never fabricated.
     *
     * @return array<string,int>
     */
    public function statusCounts(): array
    {
        $rows = Database::connection()
            ->query('SELECT sync_status, COUNT(*) AS n FROM trading_accounts GROUP BY sync_status')
            ->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['sync_status']] = (int) $r['n'];
        }
        return $out;
    }

    public function countByUser(int $userId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM trading_accounts WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function findByIdForUser(int $id, int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . '
             FROM trading_accounts WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Internal bridge read; may include encrypted-at-rest credential material. */
    public function findInternalById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM trading_accounts WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByMetaApiId(string $metaapiAccountId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . '
             FROM trading_accounts WHERE metaapi_account_id = :mid LIMIT 1'
        );
        $stmt->execute(['mid' => $metaapiAccountId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByConnectionIdentity(
        int $userId,
        string $platform,
        string $server,
        string $login,
    ): ?array {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . '
             FROM trading_accounts
             WHERE user_id = :user_id AND platform = :platform
               AND server = :server AND mt_login = :mt_login
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'platform' => strtoupper($platform),
            'server' => $server,
            'mt_login' => $login,
        ]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(array $data): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO trading_accounts
             (user_id, provider, platform, broker, server, timezone, timezone_source, mt_login, account_type,
              metaapi_account_id, sync_status, connection_credentials_encrypted,
              connected_at, auto_sync_enabled, label, account_number_masked,
              currency, leverage, status, balance, equity, starting_balance, current_balance)
             VALUES
             (:user_id, :provider, :platform, :broker, :server, :timezone, :timezone_source, :mt_login, :account_type,
              :metaapi_account_id, :sync_status, :credentials,
              CURRENT_TIMESTAMP, 1, :label, :masked, :currency, :leverage,
              :status, :balance, :equity, :starting_balance, :current_balance)'
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'provider' => $data['provider'],
            'platform' => $data['platform'] ?? $data['provider'],
            'broker' => $data['broker'] ?? null,
            'server' => $data['server'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'timezone_source' => $data['timezone_source'] ?? 'unknown',
            'mt_login' => $data['mt_login'] ?? null,
            'account_type' => $data['account_type'] ?? 'STANDARD',
            'metaapi_account_id' => $data['metaapi_account_id'] ?? null,
            'sync_status' => $data['sync_status'] ?? 'CONNECTING',
            'credentials' => $data['connection_credentials_encrypted'] ?? null,
            'label' => $data['label'] ?? '',
            'masked' => $data['account_number_masked'] ?? '',
            'currency' => $data['currency'] ?? 'USD',
            'leverage' => $data['leverage'] ?? null,
            'status' => $data['status'] ?? 'disconnected',
            'balance' => $data['balance'] ?? '0.00',
            'equity' => $data['equity'] ?? '0.00',
            'starting_balance' => $data['starting_balance'] ?? '0.00',
            'current_balance' => $data['current_balance'] ?? '0.00',
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Set/clear the broker/account SOURCE timezone (IANA) and its provenance.
     * Distinct from users.timezone (display only).
     */
    public function updateTimezone(int $id, ?string $timezone, string $source = 'user_config'): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE trading_accounts SET timezone = :tz, timezone_source = :source, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute(['tz' => $timezone, 'source' => $timezone === null ? 'unknown' : $source, 'id' => $id]);
    }

    public function updateSyncStatus(int $id, string $status, ?string $error = null): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE trading_accounts SET
               sync_status = :status,
               last_error = :error,
               consecutive_errors = CASE WHEN :is_error = 1 THEN consecutive_errors + 1 ELSE 0 END,
               connection_checked_at = CURRENT_TIMESTAMP,
               updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'error' => $error === null ? null : substr($error, 0, 500),
            'is_error' => $status === 'ERROR' ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function markSynced(int $id): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE trading_accounts SET
               sync_status = 'CONNECTED', status = 'connected',
               last_synced_at = CURRENT_TIMESTAMP, last_incremental_at = CURRENT_TIMESTAMP,
               connection_checked_at = CURRENT_TIMESTAMP, consecutive_errors = 0, last_error = NULL,
               updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    public function updateBalances(int $id, string $balance, string $equity, ?string $currency = null, ?string $leverage = null): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE trading_accounts SET balance=:balance, equity=:equity,
             current_balance=:current_balance, currency=COALESCE(:currency,currency),
             leverage=COALESCE(:leverage,leverage), updated_at=CURRENT_TIMESTAMP WHERE id=:id'
        );
        $stmt->execute([
            'balance' => $balance,
            'current_balance' => $balance,
            'equity' => $equity,
            'currency' => $currency,
            'leverage' => $leverage,
            'id' => $id,
        ]);
    }

    public function disconnect(int $id): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE trading_accounts SET sync_status='DISCONNECTED', status='disconnected',
             disconnected_at=CURRENT_TIMESTAMP, auto_sync_enabled=0,
             connection_credentials_encrypted=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=:id"
        );
        $stmt->execute(['id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM trading_accounts WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
