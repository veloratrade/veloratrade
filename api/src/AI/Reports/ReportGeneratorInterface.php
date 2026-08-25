<?php

declare(strict_types=1);

namespace Velora\AI\Reports;

use Velora\AI\DTOs\AIResponseDTO;

/**
 * P1 placeholder — Weekly/Monthly Reports.
 * Generates Persian/English intelligent reports.
 */
interface ReportGeneratorInterface
{
    /**
     * Generate weekly report for user.
     *
     * @param int $userId
     * @param string $weekStart YYYY-MM-DD
     * @param array<string,mixed> $options locale, etc.
     * @return AIResponseDTO Report content as JSON
     */
    public function generateWeekly(int $userId, string $weekStart, array $options = []): AIResponseDTO;

    public function generateMonthly(int $userId, string $monthStart, array $options = []): AIResponseDTO;

    public function isEnabled(int $userId): bool;
}
