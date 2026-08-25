<?php

declare(strict_types=1);

namespace Velora\AI\Jobs;

/**
 * Job statuses for ai_jobs table.
 */
final class AIJobStatus
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::PROCESSING,
            self::COMPLETED,
            self::FAILED,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }
}
