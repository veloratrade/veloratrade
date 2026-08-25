<?php

declare(strict_types=1);

namespace Velora\AI\Jobs;

use Velora\AI\Services\AIFeatureGuard;

/**
 * Service for AI jobs queue — no Redis, DB lease pattern.
 * For future: weekly reports, analysis, assistant when FPM cannot block 30s.
 */
final class AIJobService implements AIJobInterface
{
    private AIJobRepository $repository;
    private AIFeatureGuard $featureGuard;

    private string $type;
    private int $userId;
    private array $payload;

    public function __construct(?AIJobRepository $repository = null, ?AIFeatureGuard $featureGuard = null)
    {
        $this->repository = $repository ?? new AIJobRepository();
        $this->featureGuard = $featureGuard ?? new AIFeatureGuard();
        $this->type = 'generic';
        $this->userId = 0;
        $this->payload = [];
    }

    // AIJobInterface implementation for generic jobs
    public function getType(): string { return $this->type; }
    public function getUserId(): int { return $this->userId; }
    public function getPayload(): array { return $this->payload; }
    public function getMaxAttempts(): int { return 3; }
    public function getTimeoutSeconds(): int { return 60; }

    public function handle(): array
    {
        // Placeholder — real handling in specific job handlers (Report, Analysis)
        return ['handled' => true, 'type' => $this->type];
    }

    /**
     * Create job for async processing.
     */
    public function createJob(int $userId, string $jobType, array $payload, int $delaySeconds = 0): int
    {
        // Feature flag check for job type
        $flagMap = [
            'analysis' => 'ai_trade_analysis',
            'report' => 'ai_weekly_report',
            'weekly_report' => 'ai_weekly_report',
            'assistant' => 'ai_assistant',
        ];
        $flag = $flagMap[$jobType] ?? 'ai_' . $jobType;
        $this->featureGuard->requireEnabled($flag, $userId);

        return $this->repository->createJob($userId, $jobType, $payload, $delaySeconds);
    }

    public function claimJob(string $workerId, ?string $jobType = null): ?array
    {
        return $this->repository->claimJob($workerId, $jobType);
    }

    public function completeJob(int $jobId, array $result = []): bool
    {
        return $this->repository->completeJob($jobId, $result);
    }

    public function failJob(int $jobId, string $errorCode = 'FAILED', int $delaySeconds = 60): bool
    {
        return $this->repository->failJob($jobId, $errorCode, $delaySeconds);
    }

    public function findOwned(int $jobId, int $userId): ?array
    {
        return $this->repository->findOwned($jobId, $userId);
    }
}
