<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Config;
use Velora\Core\Database;
use Velora\Core\Exceptions\NotFoundException;

/**
 * Phase G — Billing + Subscription observability (read-only, evidence-first).
 *
 * Architecture classification: C — no real external billing system exists.
 * There is NO payment provider, NO invoice/payment/subscription/plan entity
 * table, and NO webhook receiver. The only authoritative subscription state is
 * the internal, manual, RBAC-neutral layer on `users` (v1.3):
 *   plan, subscription_status, plan_started_at, plan_expires_at, plan_updated_at.
 *
 * This service therefore reports ONLY real, runtime-backed state:
 *   - plan / subscription-status definitions derived from the authoritative
 *     ENUM values on users.plan / users.subscription_status;
 *   - live plan & subscription status distribution from `users`;
 *   - real per-user account + AI usage;
 *   - the real per-user trading-account entitlement limit from config
 *     (metaapi.max_accounts_per_user, runtime-consumed in AccountController);
 *   - the internal AI provider quota (labelled internal budget, NOT per-user).
 *
 * It NEVER fabricates: billing customer id, provider, price, currency, interval,
 * trial state, cancellation timestamp, invoices, payments, or revenue. Where no
 * authoritative value exists it reports `available: false` with a reason.
 *
 * No secrets are read or returned (no provider credentials exist).
 */
final class BillingService
{
    private const PLANS = ['free', 'pro'];
    private const SUBSCRIPTION_STATUSES = ['none', 'active', 'past_due', 'grace', 'expired', 'cancelled'];

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $pdo = Database::connection();
        $hasSubscriptionLayer = $this->hasColumn($pdo, 'users', 'plan');

        $planDistribution = [];
        $statusDistribution = [];
        if ($hasSubscriptionLayer) {
            $planDistribution = $this->distribution($pdo, 'plan', self::PLANS);
            $statusDistribution = $this->distribution($pdo, 'subscription_status', self::SUBSCRIPTION_STATUSES);
        }

        $acctLimit = max(1, (int) Config::get('metaapi.max_accounts_per_user', 10));

        return [
            'provider' => [
                'available' => false,
                'reason' => 'No external payment/billing integration exists (no provider client, no credit card, no webhook). Subscription state is internal/manual only.',
            ],
            'plans' => $this->planDefinitions($hasSubscriptionLayer),
            'subscriptionStatuses' => $this->subscriptionStatusDefinitions($hasSubscriptionLayer),
            'distribution' => [
                'available' => $hasSubscriptionLayer,
                'plan' => $planDistribution,
                'subscriptionStatus' => $statusDistribution,
            ],
            'entitlements' => [
                'tradingAccountsPerUser' => ['limit' => $acctLimit, 'source' => 'config:metaapi.max_accounts_per_user'],
                'providerBudget' => $this->providerQuota(),
            ],
            'history' => ['available' => false, 'reason' => 'No invoice/payment history exists (no billing provider).'],
        ];
    }

    /**
     * Per-user billing + entitlement state. The actor role is validated by the
     * controller's permission middleware; only a target that exists is returned.
     *
     * @return array<string,mixed>
     */
    public function user(int $id): array
    {
        $pdo = Database::connection();
        $row = $pdo->prepare('SELECT id, email, plan, subscription_status, plan_started_at, plan_expires_at, plan_updated_at FROM users WHERE id = :id LIMIT 1');
        $row->execute(['id' => $id]);
        $u = $row->fetch();
        if ($u === false) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }

        $hasLayer = $this->hasColumn($pdo, 'users', 'plan');
        $acctLimit = max(1, (int) Config::get('metaapi.max_accounts_per_user', 10));
        $accounts = $this->count($pdo, 'trading_accounts', $id);
        $aiUsage = $this->aiUsage($pdo, $id);

        return [
            'user' => [
                'id' => (int) $u['id'],
                'email' => $u['email'],
            ],
            'subscription' => [
                'available' => $hasLayer,
                'plan' => $hasLayer ? ($u['plan'] ?? 'free') : null,
                'status' => $hasLayer ? ($u['subscription_status'] ?? 'none') : null,
                'startedAt' => $hasLayer ? ($u['plan_started_at'] ?? null) : null,
                'expiresAt' => $hasLayer ? ($u['plan_expires_at'] ?? null) : null,
                'updatedAt' => $hasLayer ? ($u['plan_updated_at'] ?? null) : null,
                'provider' => null,
                'billingCustomerId' => null,
                'trial' => false,
                'cancelledAt' => null,
                'period' => null,
            ],
            'entitlements' => [
                'tradingAccounts' => [
                    'used' => (int) $accounts,
                    'limit' => $acctLimit,
                    'source' => 'config:metaapi.max_accounts_per_user',
                ],
                'aiUsage' => $aiUsage,
            ],
            'history' => ['available' => false, 'reason' => 'No invoice/payment history exists (no billing provider).'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function planDefinitions(bool $hasLayer): array
    {
        $out = [];
        foreach (self::PLANS as $key) {
            $out[] = [
                'key' => $key,
                'name' => $key === 'pro' ? 'Pro' : 'Free',
                'description' => $key === 'pro' ? 'Professional plan' : 'Free plan',
                // price/currency/interval are NOT authoritative — no billing provider.
                'price' => ['available' => false, 'reason' => 'Plan price is not authoritative: no external billing/pricing source exists.'],
                'currency' => null,
                'interval' => null,
                'active' => true,
                'available' => $hasLayer,
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function subscriptionStatusDefinitions(bool $hasLayer): array
    {
        $out = [];
        foreach (self::SUBSCRIPTION_STATUSES as $key) {
            $out[] = ['key' => $key, 'active' => true, 'available' => $hasLayer];
        }
        return $out;
    }

    /** @param list<string> $allowed */
    private function distribution(\PDO $pdo, string $column, array $allowed): array
    {
        $stmt = $pdo->prepare('SELECT ' . $column . ' AS k, COUNT(*) AS n FROM users GROUP BY ' . $column);
        try {
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[(string) $r['k']] = (int) $r['n'];
        }
        $out = [];
        foreach ($allowed as $key) {
            $out[] = ['key' => $key, 'count' => $byKey[$key] ?? 0];
        }
        return $out;
    }

    private function count(\PDO $pdo, string $table, int $id): int
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM ' . $table . ' WHERE user_id = :id');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            return (int) ($row['n'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** @return array<string,mixed> */
    private function aiUsage(\PDO $pdo, int $id): array
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS total,
                        SUM(CASE WHEN status IN (\'failed\',\'quota_exhausted\',\'timeout\') THEN 1 ELSE 0 END) AS failed,
                        SUM(CASE WHEN status=\'success\' THEN tokens_used ELSE 0 END) AS tokens
                 FROM ai_requests WHERE user_id = :id'
            );
            $stmt->execute(['id' => $id]);
            $r = $stmt->fetch();
            return [
                'requests' => (int) ($r['total'] ?? 0),
                'failed' => (int) ($r['failed'] ?? 0),
                'tokensUsed' => (int) ($r['tokens'] ?? 0),
                'available' => true,
            ];
        } catch (\Throwable $e) {
            return ['requests' => 0, 'failed' => 0, 'tokensUsed' => 0, 'available' => false];
        }
    }

    /** Internal provider budget (NOT a per-user billing entitlement). */
    private function providerQuota(): array
    {
        if (!$this->hasColumn(Database::connection(), 'ai_provider_quotas', 'quota_limit')) {
            return ['available' => false];
        }
        try {
            $rows = Database::connection()->query('SELECT provider, daily_used, quota_limit, reset_at FROM ai_provider_quotas ORDER BY provider')->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'provider' => (string) $r['provider'],
                    'used' => (int) ($r['daily_used'] ?? 0),
                    'limit' => (int) ($r['quota_limit'] ?? 1500),
                    'resetAt' => $r['reset_at'] ?? null,
                ];
            }
            return ['available' => true, 'internal' => true, 'items' => $out];
        } catch (\Throwable $e) {
            return ['available' => false];
        }
    }

    private function hasColumn(\PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = "$table.$column";
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        try {
            $driver = (string) ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) ?? '');
            if ($driver === 'sqlite') {
                $rows = $pdo->query("PRAGMA table_info($table)")->fetchAll();
                $cache[$key] = false;
                foreach ($rows as $r) {
                    if (($r['name'] ?? null) === $column) {
                        return $cache[$key] = true;
                    }
                }
                return false;
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
            $stmt->execute(['t' => $table, 'c' => $column]);
            $cache[$key] = ((int) $stmt->fetch()['n']) > 0;
        } catch (\Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}
