<?php

declare(strict_types=1);

namespace Velora\AI\Jobs;

use Velora\AI\Repositories\AIRepository;

/**
 * Repository for ai_jobs — async AI jobs queue.
 * No Redis, uses DB lease pattern like MetaApi worker (fenced queue).
 * Follows existing repository pattern.
 */
final class AIJobRepository extends AIRepository
{
    private const TABLE = 'ai_jobs';

    /**
     * Create job.
     *
     * @param array<string,mixed> $payload
     * @return int New job ID
     */
    public function createJob(int $userId, string $jobType, array $payload, int $availableAtDelaySeconds = 0): int
    {
        $availableAt = gmdate('Y-m-d H:i:s', time() + $availableAtDelaySeconds);

        $stmt = $this->connection()->prepare(
            'INSERT INTO ' . self::TABLE . ' (user_id, job_type, payload, status, available_at)
             VALUES (:user_id, :job_type, :payload, :status, :available_at)'
        );

        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':job_type', $jobType);
        $stmt->bindValue(':payload', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':status', AIJobStatus::PENDING);
        $stmt->bindValue(':available_at', $availableAt);

        $stmt->execute();
        return (int) $this->connection()->lastInsertId();
    }

    /**
     * Claim next pending job — atomic lease acquisition (fenced queue).
     * Same pattern as MetaApiService::runNextSyncJob().
     *
     * @return array<string,mixed>|null
     */
    public function claimJob(string $workerId, ?string $jobType = null): ?array
    {
        try {
            $pdo = $this->connection();
            $pdo->beginTransaction();

            $sql = 'SELECT * FROM ' . self::TABLE . '
                    WHERE status = :pending AND available_at <= NOW()
                    AND (attempts < 3 OR attempts IS NULL)';
            $params = ['pending' => AIJobStatus::PENDING];

            if ($jobType !== null) {
                $sql .= ' AND job_type = :job_type';
                $params['job_type'] = $jobType;
            }

            $sql .= ' ORDER BY created_at ASC LIMIT 1 FOR UPDATE';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $job = $stmt->fetch();

            if ($job === false) {
                $pdo->rollBack();
                return null;
            }

            // Claim it
            $update = $pdo->prepare(
                'UPDATE ' . self::TABLE . '
                 SET status = :processing, attempts = COALESCE(attempts,0) + 1, updated_at = NOW()
                 WHERE id = :id AND status = :pending'
            );
            $update->execute([
                'processing' => AIJobStatus::PROCESSING,
                'id' => $job['id'],
                'pending' => AIJobStatus::PENDING,
            ]);

            if ($update->rowCount() === 0) {
                $pdo->rollBack();
                return null;
            }

            $pdo->commit();

            // Decode payload
            $job['payload'] = json_decode($job['payload'] ?? '{}', true) ?: [];
            return $job;
        } catch (\Throwable $e) {
            try {
                $this->connection()->rollBack();
            } catch (\Throwable $e2) {
            }
            error_log('[VELORA_AI_JOBS] claim failed: ' . $e->getMessage());
            return null;
        }
    }

    public function completeJob(int $jobId, array $result = []): bool
    {
        try {
            $stmt = $this->connection()->prepare(
                'UPDATE ' . self::TABLE . '
                 SET status = :completed, payload = JSON_SET(COALESCE(payload, JSON_OBJECT()), "$.result", CAST(:result AS JSON)), updated_at = NOW()
                 WHERE id = :id'
            );
            // For MySQL, JSON_SET, for SQLite fallback we just update status
            $driver = $this->connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->connection()->prepare(
                    'UPDATE ' . self::TABLE . ' SET status = :completed, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                );
                $stmt->execute(['completed' => AIJobStatus::COMPLETED, 'id' => $jobId]);
            } else {
                $stmt->execute([
                    'completed' => AIJobStatus::COMPLETED,
                    'result' => json_encode($result, JSON_UNESCAPED_SLASHES),
                    'id' => $jobId,
                ]);
            }
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_JOBS] complete failed: ' . $e->getMessage());
            return false;
        }
    }

    public function failJob(int $jobId, string $errorCode = 'FAILED', int $delaySeconds = 60): bool
    {
        try {
            $availableAt = gmdate('Y-m-d H:i:s', time() + $delaySeconds);
            $stmt = $this->connection()->prepare(
                'UPDATE ' . self::TABLE . '
                 SET status = :failed, available_at = :available_at, updated_at = NOW()
                 WHERE id = :id'
            );
            // If attempts >=3, keep failed, else set pending for retry
            $pdo = $this->connection();
            $check = $pdo->prepare('SELECT attempts FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
            $check->execute(['id' => $jobId]);
            $row = $check->fetch();
            $attempts = (int) ($row['attempts'] ?? 0);
            $status = $attempts >= 3 ? AIJobStatus::FAILED : AIJobStatus::PENDING;

            $stmt = $this->connection()->prepare(
                'UPDATE ' . self::TABLE . ' SET status = :status, available_at = :available_at, updated_at = NOW() WHERE id = :id'
            );
            $stmt->execute([
                'status' => $status,
                'available_at' => $availableAt,
                'id' => $jobId,
            ]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_JOBS] fail failed: ' . $e->getMessage());
            return false;
        }
    }

    public function findOwned(int $jobId, int $userId): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['id' => $jobId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['payload'] = json_decode($row['payload'] ?? '{}', true) ?: [];
        return $row;
    }

    public function recentForUser(int $userId, int $limit = 20): array
    {
        $stmt = $this->connection()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['payload'] = json_decode($r['payload'] ?? '{}', true) ?: [];
        }
        return $rows;
    }
}
