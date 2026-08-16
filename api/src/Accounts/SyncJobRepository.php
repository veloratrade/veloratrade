<?php

declare(strict_types=1);

namespace Velora\Accounts;

use PDOException;
use Velora\Core\Config;
use Velora\Core\Database;

/**
 * Fenced MetaApi queue with atomic claims, stale-lease recovery, bounded
 * exponential retry and retained dead letters.
 */
final class SyncJobRepository
{
    /**
     * @return array{id:int,enqueued:bool}
     */
    public function enqueue(
        int $accountId,
        int $userId,
        string $type = 'INCREMENTAL',
        ?array $payload = null,
        ?int $cooldownSeconds = null,
    ): array {
        $type = strtoupper($type);
        if (!in_array($type, ['HISTORICAL', 'INCREMENTAL', 'WEBHOOK'], true)) {
            throw new \InvalidArgumentException('Unsupported MetaApi sync job type.');
        }
        $cooldownSeconds ??= max(1, (int) Config::get('metaapi.sync_cooldown_seconds', 60));
        $threshold = gmdate('Y-m-d H:i:s', time() - $cooldownSeconds);

        $existing = $this->findRecentOrActive($accountId, $threshold);
        if ($existing !== null) {
            return ['id' => (int) $existing['id'], 'enqueued' => false];
        }

        $dedupeKey = 'metaapi-sync:' . $accountId;
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO sync_jobs
                 (account_id, user_id, type, status, payload, attempts, max_attempts,
                  available_at, dedupe_key, created_at, updated_at)
                 VALUES
                 (:account_id, :user_id, :type, :status, :payload, 0, :max_attempts,
                  CURRENT_TIMESTAMP, :dedupe_key, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                'account_id' => $accountId,
                'user_id' => $userId,
                'type' => $type,
                'status' => 'PENDING',
                'payload' => $payload === null ? null : json_encode($this->redactSensitive($payload), JSON_THROW_ON_ERROR),
                'max_attempts' => max(1, (int) Config::get('metaapi.sync_max_attempts', 5)),
                'dedupe_key' => $dedupeKey,
            ]);
            return ['id' => (int) Database::connection()->lastInsertId(), 'enqueued' => true];
        } catch (PDOException $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }
            $existing = $this->findActiveByDedupeKey($dedupeKey);
            if ($existing === null) {
                throw $e;
            }
            return ['id' => (int) $existing['id'], 'enqueued' => false];
        }
    }

    public function claimNext(?string $workerId = null, int $leaseSeconds = 300): ?array
    {
        $workerId ??= 'metaapi-' . gethostname() . '-' . getmypid();
        $workerId = substr($workerId, 0, 96);
        $leaseSeconds = max(30, min($leaseSeconds, 3600));
        $now = gmdate('Y-m-d H:i:s');
        $staleBefore = gmdate('Y-m-d H:i:s', time() - $leaseSeconds);
        $leaseToken = bin2hex(random_bytes(32));

        // A stale final attempt is terminal and retained; it is never silently
        // recycled or deleted.
        $reap = Database::connection()->prepare(
            "UPDATE sync_jobs SET status='DEAD_LETTER', dead_lettered_at=CURRENT_TIMESTAMP,
             completed_at=CURRENT_TIMESTAMP, locked_at=NULL, locked_by=NULL, lease_token=NULL,
             dedupe_key=NULL, updated_at=CURRENT_TIMESTAMP
             WHERE status='RUNNING' AND locked_at < :stale_before AND attempts >= max_attempts"
        );
        $reap->execute(['stale_before' => $staleBefore]);

        $pdo = Database::connection();
        if ((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            // MySQL 8 workers skip rows already locked by peers, preserving
            // throughput without ever sharing ownership of an attempt.
            return Database::transaction(function (\PDO $tx) use (
                $now,
                $staleBefore,
                $workerId,
                $leaseToken,
            ): ?array {
                $candidate = $tx->prepare(
                    "SELECT id FROM sync_jobs
                     WHERE ((status='PENDING' AND available_at <= :candidate_now)
                        OR (status='RUNNING' AND locked_at < :candidate_stale))
                       AND attempts < max_attempts
                     ORDER BY available_at ASC, id ASC
                     LIMIT 1 FOR UPDATE SKIP LOCKED"
                );
                $candidate->execute([
                    'candidate_now' => $now,
                    'candidate_stale' => $staleBefore,
                ]);
                $jobId = $candidate->fetchColumn();
                if ($jobId === false) {
                    return null;
                }

                $claim = $tx->prepare(
                    "UPDATE sync_jobs SET status='RUNNING', attempts=attempts+1,
                     locked_at=:locked_at, locked_by=:locked_by, lease_token=:lease_token,
                     started_at=COALESCE(started_at, CURRENT_TIMESTAMP), updated_at=CURRENT_TIMESTAMP
                     WHERE id=:id AND ((status='PENDING' AND available_at <= :guard_now)
                       OR (status='RUNNING' AND locked_at < :guard_stale))
                       AND attempts < max_attempts"
                );
                $claim->execute([
                    'locked_at' => $now,
                    'locked_by' => $workerId,
                    'lease_token' => $leaseToken,
                    'id' => (int) $jobId,
                    'guard_now' => $now,
                    'guard_stale' => $staleBefore,
                ]);
                if ($claim->rowCount() !== 1) {
                    return null;
                }
                $select = $tx->prepare(
                    'SELECT * FROM sync_jobs WHERE id=:id AND lease_token=:lease_token AND locked_by=:locked_by LIMIT 1'
                );
                $select->execute([
                    'id' => (int) $jobId,
                    'lease_token' => $leaseToken,
                    'locked_by' => $workerId,
                ]);
                $job = $select->fetch();
                return $job === false ? null : $job;
            });
        }

        // SQLite fixture/runtime fallback: one guarded UPDATE owns the claim.
        $sql = "UPDATE sync_jobs SET
                  status='RUNNING', attempts=attempts+1, locked_at=:locked_at,
                  locked_by=:locked_by, lease_token=:lease_token,
                  started_at=COALESCE(started_at, CURRENT_TIMESTAMP), updated_at=CURRENT_TIMESTAMP
                WHERE id = (
                  SELECT id FROM (
                    SELECT id FROM sync_jobs
                    WHERE ((status='PENDING' AND available_at <= :candidate_now)
                       OR (status='RUNNING' AND locked_at < :candidate_stale))
                      AND attempts < max_attempts
                    ORDER BY available_at ASC, id ASC LIMIT 1
                  ) AS claim_candidate
                )
                AND ((status='PENDING' AND available_at <= :guard_now)
                  OR (status='RUNNING' AND locked_at < :guard_stale))
                AND attempts < max_attempts";
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'locked_at' => $now,
            'locked_by' => $workerId,
            'lease_token' => $leaseToken,
            'candidate_now' => $now,
            'candidate_stale' => $staleBefore,
            'guard_now' => $now,
            'guard_stale' => $staleBefore,
        ]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }

        $select = Database::connection()->prepare(
            'SELECT * FROM sync_jobs WHERE lease_token = :lease_token AND locked_by = :locked_by LIMIT 1'
        );
        $select->execute(['lease_token' => $leaseToken, 'locked_by' => $workerId]);
        $job = $select->fetch();
        return $job === false ? null : $job;
    }

    public function complete(int $jobId, string $leaseToken): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE sync_jobs SET status='COMPLETED', completed_at=CURRENT_TIMESTAMP,
             locked_at=NULL, locked_by=NULL, lease_token=NULL, dedupe_key=NULL,
             last_error=NULL, updated_at=CURRENT_TIMESTAMP
             WHERE id=:id AND status='RUNNING' AND lease_token=:lease_token"
        );
        $stmt->execute(['id' => $jobId, 'lease_token' => $leaseToken]);
        return $stmt->rowCount() === 1;
    }

    /** @return array{updated:bool,status:string,backoff_seconds:int} */
    public function fail(int $jobId, string $leaseToken, string $error): array
    {
        $select = Database::connection()->prepare(
            "SELECT attempts, max_attempts FROM sync_jobs
             WHERE id=:id AND status='RUNNING' AND lease_token=:lease_token LIMIT 1"
        );
        $select->execute(['id' => $jobId, 'lease_token' => $leaseToken]);
        $job = $select->fetch();
        if ($job === false) {
            return ['updated' => false, 'status' => 'LEASE_LOST', 'backoff_seconds' => 0];
        }

        $attempts = (int) $job['attempts'];
        $maxAttempts = (int) $job['max_attempts'];
        $safeError = $this->sanitizeError($error);
        if ($attempts >= $maxAttempts) {
            $stmt = Database::connection()->prepare(
                "UPDATE sync_jobs SET status='DEAD_LETTER', last_error=:last_error,
                 dead_lettered_at=CURRENT_TIMESTAMP, completed_at=CURRENT_TIMESTAMP,
                 locked_at=NULL, locked_by=NULL, lease_token=NULL, dedupe_key=NULL,
                 updated_at=CURRENT_TIMESTAMP
                 WHERE id=:id AND status='RUNNING' AND lease_token=:lease_token"
            );
            $stmt->execute(['last_error' => $safeError, 'id' => $jobId, 'lease_token' => $leaseToken]);
            return [
                'updated' => $stmt->rowCount() === 1,
                'status' => 'DEAD_LETTER',
                'backoff_seconds' => 0,
            ];
        }

        $base = max(1, (int) Config::get('metaapi.sync_retry_base_seconds', 30));
        $cap = max($base, (int) Config::get('metaapi.sync_retry_cap_seconds', 3600));
        $backoff = min($cap, $base * (2 ** max(0, $attempts - 1)));
        $availableAt = gmdate('Y-m-d H:i:s', time() + $backoff);
        $stmt = Database::connection()->prepare(
            "UPDATE sync_jobs SET status='PENDING', last_error=:last_error,
             available_at=:available_at, locked_at=NULL, locked_by=NULL, lease_token=NULL,
             updated_at=CURRENT_TIMESTAMP
             WHERE id=:id AND status='RUNNING' AND lease_token=:lease_token"
        );
        $stmt->execute([
            'last_error' => $safeError,
            'available_at' => $availableAt,
            'id' => $jobId,
            'lease_token' => $leaseToken,
        ]);
        return [
            'updated' => $stmt->rowCount() === 1,
            'status' => 'PENDING',
            'backoff_seconds' => $backoff,
        ];
    }

    public function recentForAccount(int $accountId, int $limit = 5): array
    {
        $limit = max(1, min($limit, 50));
        $stmt = Database::connection()->prepare(
            'SELECT id, type, status, attempts, max_attempts, available_at,
                    created_at, started_at, completed_at, dead_lettered_at
             FROM sync_jobs WHERE account_id=:account_id ORDER BY id DESC LIMIT :limit'
        );
        $stmt->bindValue(':account_id', $accountId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function findRecentOrActive(int $accountId, string $threshold): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id FROM sync_jobs
             WHERE account_id=:account_id
               AND (status IN ('PENDING','RUNNING') OR created_at >= :threshold)
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['account_id' => $accountId, 'threshold' => $threshold]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function findActiveByDedupeKey(string $dedupeKey): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id FROM sync_jobs
             WHERE dedupe_key=:dedupe_key AND status IN ('PENDING','RUNNING') LIMIT 1"
        );
        $stmt->execute(['dedupe_key' => $dedupeKey]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function sanitizeError(string $error): string
    {
        $error = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+/i', 'Bearer [redacted]', $error) ?? $error;
        $error = preg_replace('/(password|token|authorization)\s*[=:]\s*\S+/i', '$1=[redacted]', $error) ?? $error;
        return substr($error, 0, 1000);
    }

    private function redactSensitive(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (preg_match('/password|token|authorization|credential|secret/i', (string) $key)) {
                $payload[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->redactSensitive($value);
            }
        }
        return $payload;
    }

    private function isUniqueViolation(PDOException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        return $sqlState === '23000' || $sqlState === '19';
    }
}
