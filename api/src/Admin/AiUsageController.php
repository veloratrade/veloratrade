<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\AI\Repositories\AIProviderQuotaRepository;
use Velora\AI\Repositories\AIRequestRepository;
use Velora\AI\Services\ProviderCatalog;
use Velora\Core\Database;
use Velora\Core\Exceptions\ValidationException;
use Velora\Core\Request;
use Velora\Core\Response;

/**
 * Phase 5 — per-request AI usage drill-down for the frozen Admin v2.2
 * "AI Usage" page (#/ai-usage).
 *
 * Read-only listing endpoint over the existing `ai_requests` ledger.
 * Authorization is enforced at the route (admin stack + `aiManage` — the
 * same permission the frozen frontend already assigns to #/ai-usage and the
 * permission both panel roles hold). Every filter/sort value is whitelisted
 * or rejected with the standard validation envelope; every statement is
 * prepared; pagination is bounded (per_page 1..100). The projection is an
 * explicit safe column list: `prompt_hash` is never returned, and no
 * prompt/response payload, credential or raw provider-error material exists
 * in this table or is joined in.
 *
 * Honest scope (per the frozen spec §5.8): `ai_requests.status` reflects the
 * recorded ledger only — the table receives rows for successful provider
 * calls (failures live in `ai_provider_logs`, outside this drill-down's
 * frozen scope). `cost` is the recorded internal accounting value
 * (free-tier providers record 0), NOT provider billing.
 * The per-page user identity is resolved with ONE additional `users` query
 * per response (no N+1).
 */
final class AiUsageController
{
    /** Real `ai_requests.status` ENUM values (v0.5). */
    private const STATUSES = ['success', 'failed', 'quota_exhausted', 'timeout'];
    /** Real `ai_requests.feature` vocabulary written by the three AIManager call sites + the manager default. */
    private const FEATURES = ['extraction', 'analysis', 'weekly_report', 'generic'];
    /** Whitelisted sort fields. */
    private const ORDERS = ['created_at', 'tokens_used', 'latency_ms', 'cost'];

    /** GET /api/v1/admin/ai-usage */
    public function usage(Request $request): never
    {
        $pg = $this->page($request);
        $order = (string) ($request->query['order'] ?? '');
        if ($order !== '' && !in_array($order, self::ORDERS, true)) {
            throw $this->invalid('order');
        }
        $dir = strtolower((string) ($request->query['dir'] ?? ''));
        if ($dir !== '' && !in_array($dir, ['asc', 'desc'], true)) {
            throw $this->invalid('dir');
        }

        $res = (new AIRequestRepository())->searchGlobal(
            [
                'user_id' => $this->idFilter($request, 'user_id'),
                'feature' => $this->enum($request, 'feature', self::FEATURES),
                'provider' => $this->provider($request),
                'model' => $this->model($request),
                'status' => $this->enum($request, 'status', self::STATUSES),
                'date_from' => $this->dateFilter($request, 'date_from'),
                'date_to' => $this->dateFilter($request, 'date_to'),
            ],
            [
                'limit' => $pg['perPage'],
                'offset' => ($pg['page'] - 1) * $pg['perPage'],
                'order' => $order,
                'dir' => $dir,
            ],
        );

        Response::json([
            'requests' => $this->withUsers(array_map(fn (array $r): array => $this->row($r), $res['items'])),
            'quotas' => $this->quotas(),
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

    /** Provider filter validated against the server-side ProviderCatalog allowlist. */
    private function provider(Request $request): ?string
    {
        $v = (string) ($request->query['provider'] ?? '');
        if ($v === '') {
            return null;
        }
        if (!in_array($v, ProviderCatalog::providerNames(), true)) {
            throw $this->invalid('provider');
        }
        return $v;
    }

    /** Model filter: exact match, bounded length (column is VARCHAR(64)); bound as a parameter — no LIKE, no wildcards. */
    private function model(Request $request): ?string
    {
        $v = trim((string) ($request->query['model'] ?? ''));
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > 64) {
            throw $this->invalid('model');
        }
        return $v;
    }

    /** Date bounds must be real Y-m-d days; widened to full-day boundaries at the repository. */
    private function dateFilter(Request $request, string $field): ?string
    {
        $v = trim((string) ($request->query[$field] ?? ''));
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || strtotime($v) === false) {
            throw new ValidationException('Invalid filter.', [$field => ['code' => 'INVALID_DATE']]);
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

    /** Real quota rows (ai_provider_quotas) for the catalog providers — internal free-tier budget counters, not billing. */
    private function quotas(): array
    {
        $repo = new AIProviderQuotaRepository();
        $out = [];
        foreach (ProviderCatalog::providerNames() as $p) {
            $q = $repo->getQuota($p);
            if ($q !== null) {
                $out[] = [
                    'provider' => $p,
                    'dailyUsed' => (int) ($q['daily_used'] ?? 0),
                    'quotaLimit' => (int) ($q['quota_limit'] ?? 0),
                    'resetAt' => $q['reset_at'] !== null ? (string) $q['reset_at'] : null,
                ];
            }
        }
        return $out;
    }

    /** Safe Admin projection of an ai_requests row (camelCase; `prompt_hash` intentionally omitted). */
    private function row(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'userId' => (int) $r['user_id'],
            'feature' => (string) $r['feature'],
            'provider' => (string) $r['provider'],
            'model' => (string) $r['model'],
            'status' => (string) $r['status'],
            'tokensUsed' => (int) $r['tokens_used'],
            'latencyMs' => (int) $r['latency_ms'],
            'cost' => (string) $r['cost'],
            'createdAt' => (string) $r['created_at'],
        ];
    }
}
