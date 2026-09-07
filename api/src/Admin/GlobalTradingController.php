<?php

declare(strict_types=1);

namespace Velora\Admin;

use PDO;
use Velora\Accounts\AccountRepository;
use Velora\Core\Database;
use Velora\Core\Exceptions\ValidationException;
use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Trades\TradeRepository;

/**
 * Phase 4 — global (platform-wide) trading data for the frozen Admin v2.2
 * "Trading Accounts" and "Trades" pages.
 *
 * Read-only listing endpoints. Authorization is enforced at the route
 * (admin stack + `users.view` — the same permission as the per-user admin
 * views and the permission the frozen frontend already assigns to these
 * routes). Every filter/sort value is whitelisted or rejected with the
 * standard validation envelope; every statement is prepared; pagination is
 * bounded (per_page 1..100). Projections never include credential material
 * (no `connection_credentials_encrypted`, no MetaAPI token material, no
 * masked-blob fields). The per-page user identity is resolved with ONE
 * additional `users` query per response (no N+1).
 */
final class GlobalTradingController
{
    private const SYNC_STATUSES = ['DISCONNECTED', 'CONNECTING', 'SYNCING', 'CONNECTED', 'ERROR'];
    private const PLATFORMS = ['MT4', 'MT5', 'MANUAL'];
    private const TRADE_ORDERS = ['open_time', 'profit_loss', 'close_time'];
    private const ACCOUNT_ORDERS = ['created_at', 'balance', 'last_synced_at'];
    private const DIRECTIONS = ['buy', 'sell'];

    /** GET /api/v1/admin/trading-accounts */
    public function accounts(Request $request): never
    {
        $pg = $this->page($request);
        $order = (string) ($request->query['order'] ?? '');
        if ($order !== '' && !in_array($order, self::ACCOUNT_ORDERS, true)) {
            throw $this->invalid('order');
        }

        $repo = new AccountRepository();
        $res = $repo->searchGlobal(
            [
                'user_id' => $this->idFilter($request, 'user_id'),
                'status' => $this->enum($request, 'status', self::SYNC_STATUSES),
                'platform' => $this->enum($request, 'platform', self::PLATFORMS),
                'q' => trim((string) ($request->query['q'] ?? '')),
            ],
            ['limit' => $pg['perPage'], 'offset' => ($pg['page'] - 1) * $pg['perPage'], 'order' => $order],
        );

        Response::json([
            'accounts' => $this->withUsers(array_map(fn (array $r): array => $this->accountRow($r), $res['items'])),
            'stats' => ['byStatus' => $repo->statusCounts()],
            'pagination' => $this->pagination($res['total'], $pg),
        ]);
    }

    /** GET /api/v1/admin/trades */
    public function trades(Request $request): never
    {
        $pg = $this->page($request);
        $order = (string) ($request->query['order'] ?? '');
        if ($order !== '' && !in_array($order, self::TRADE_ORDERS, true)) {
            throw $this->invalid('order');
        }
        $direction = (string) ($request->query['direction'] ?? '');
        if ($direction !== '' && !in_array($direction, self::DIRECTIONS, true)) {
            throw $this->invalid('direction');
        }

        $res = (new TradeRepository())->searchGlobal(
            [
                'user_id' => $this->idFilter($request, 'user_id'),
                'account_id' => $this->idFilter($request, 'account_id'),
                'symbol' => trim((string) ($request->query['symbol'] ?? '')),
                'direction' => $direction,
                'from' => trim((string) ($request->query['from'] ?? '')),
                'to' => trim((string) ($request->query['to'] ?? '')),
                'pnl_min' => $this->decimalFilter($request, 'pnl_min'),
                'pnl_max' => $this->decimalFilter($request, 'pnl_max'),
                'q' => trim((string) ($request->query['q'] ?? '')),
            ],
            ['limit' => $pg['perPage'], 'offset' => ($pg['page'] - 1) * $pg['perPage'], 'order' => $order],
        );

        Response::json([
            'trades' => $this->withUsers(array_map(fn (array $r): array => $this->tradeRow($r), $res['items'])),
            'pagination' => $this->pagination($res['total'], $pg),
        ]);
    }

    /** @return array{page:int, perPage:int} */
    private function page(Request $request): array
    {
        return [
            'page' => max(1, (int) ($request->query['page'] ?? 1)),
            'perPage' => min(100, max(1, (int) ($request->query['per_page'] ?? 25))),
        ];
    }

    /** @return array{total:int, page:int, per_page:int, has_more:bool} */
    private function pagination(int $total, array $pg): array
    {
        return ['total' => $total, 'page' => $pg['page'], 'per_page' => $pg['perPage'], 'has_more' => $pg['page'] * $pg['perPage'] < $total];
    }

    private function enum(Request $request, string $field, array $allowed): ?string
    {
        $v = (string) ($request->query[$field] ?? '');
        if ($v === '') {
            return null;
        }
        if (!in_array($v, $allowed, true)) {
            throw $this->invalid($field);
        }
        return $v;
    }

    private function idFilter(Request $request, string $field): ?int
    {
        $v = (string) ($request->query[$field] ?? '');
        if ($v === '' || $v === '0') {
            return null;
        }
        if (!ctype_digit($v)) {
            throw $this->invalid($field);
        }
        return (int) $v;
    }

    private function decimalFilter(Request $request, string $field): ?string
    {
        $v = (string) ($request->query[$field] ?? '');
        if ($v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            throw $this->invalid($field);
        }
        return $v;
    }

    private function invalid(string $field): ValidationException
    {
        return new ValidationException('Invalid filter.', [$field => ['code' => 'INVALID_CHOICE', 'messageKey' => 'errors.validation.choice', 'params' => []]]);
    }

    /** One identity query per page of rows — never per row. */
    private function withUsers(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $ids = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['userId'], $rows)));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare("SELECT id, email, full_name FROM users WHERE id IN ({$ph})");
        $stmt->execute($ids);
        $byId = [];
        foreach ($stmt->fetchAll() as $u) {
            $byId[(int) $u['id']] = $u;
        }
        foreach ($rows as &$r) {
            $u = $byId[(int) $r['userId']] ?? null;
            $r['userEmail'] = $u['email'] ?? null;
            $r['userFullName'] = $u['full_name'] ?? null;
        }
        return $rows;
    }

    /** Safe Admin projection of a trading_accounts row (masked account first; no credential material, no raw provider error text). */
    private function accountRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'userId' => (int) $r['user_id'],
            'provider' => (string) ($r['provider'] ?? 'MANUAL'),
            'platform' => (string) ($r['platform'] ?? 'MANUAL'),
            'broker' => $r['broker'] !== null ? (string) $r['broker'] : null,
            'server' => $r['server'] !== null ? (string) $r['server'] : null,
            'accountNumber' => (string) ($r['account_number_masked'] ?? '') !== '' ? (string) $r['account_number_masked'] : ($r['mt_login'] !== null ? (string) $r['mt_login'] : null),
            'accountType' => (string) ($r['account_type'] ?? 'STANDARD'),
            'syncStatus' => (string) ($r['sync_status'] ?? 'DISCONNECTED'),
            'connectedAt' => $r['connected_at'] !== null ? (string) $r['connected_at'] : null,
            'lastSyncedAt' => $r['last_synced_at'] !== null ? (string) $r['last_synced_at'] : null,
            'label' => (string) ($r['label'] ?? ''),
            'currency' => (string) ($r['currency'] ?? 'USD'),
            'leverage' => $r['leverage'] !== null ? (string) $r['leverage'] : null,
            'balance' => (string) ($r['balance'] ?? '0.00'),
            'equity' => (string) ($r['equity'] ?? '0.00'),
            'status' => (string) ($r['status'] ?? 'disconnected'),
            'createdAt' => (string) ($r['created_at'] ?? ''),
        ];
    }

    /** Safe Admin projection of a trade row (camelCase; journal `notes` intentionally omitted from the platform-wide list). */
    private function tradeRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'userId' => (int) $r['user_id'],
            'accountId' => $r['account_id'] !== null ? (int) $r['account_id'] : null,
            'symbol' => (string) $r['symbol'],
            'direction' => (string) $r['direction'],
            'entryPrice' => (string) $r['entry_price'],
            'exitPrice' => (string) $r['exit_price'],
            'volume' => (string) $r['volume'],
            'commission' => (string) $r['commission'],
            'swap' => (string) $r['swap'],
            'profitLoss' => (string) $r['profit_loss'],
            'openTime' => (string) $r['open_time'],
            'closeTime' => (string) $r['close_time'],
            'strategyTag' => $r['strategy_tag'] !== null ? (string) $r['strategy_tag'] : null,
            'source' => (string) ($r['source'] ?? 'manual'),
        ];
    }
}
