<?php

declare(strict_types=1);

namespace Velora\AI\Reports;

use Velora\AI\Analysis\TradeAnalyzerService;
use Velora\AI\Exceptions\AIValidationException;
use Velora\AI\Prompts\PromptManager;
use Velora\AI\Services\AIFeatureGuard;
use Velora\AI\Services\AIManager;

/**
 * Weekly Report Service — P1 implementation.
 * Uses Analysis module, does not query trades table directly, receives TradeDataDTO[].
 * Supports fa/en via PromptManager, ownership validation, duplicate prevention.
 */
final class WeeklyReportService implements ReportGeneratorInterface
{
    private const MAX_TRADES = 200;
    private const MAX_JSON_BYTES = 300_000;

    private AIManager $manager;
    private ReportRepository $repository;
    private TradeAnalyzerService $analyzer;
    private AIFeatureGuard $featureGuard;

    public function __construct(?AIManager $manager = null, ?ReportRepository $repository = null, ?TradeAnalyzerService $analyzer = null, ?AIFeatureGuard $featureGuard = null)
    {
        $this->manager = $manager ?? new AIManager();
        $this->repository = $repository ?? new ReportRepository();
        $this->analyzer = $analyzer ?? new TradeAnalyzerService($manager, null, $featureGuard);
        $this->featureGuard = $featureGuard ?? new AIFeatureGuard();
    }

    public function generateWeekly(int $userId, string $weekStart, array $options = []): \Velora\AI\DTOs\AIResponseDTO
    {
        $this->featureGuard->requireEnabled('ai_weekly_report', $userId);

        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user_id');
        }

        // Validate period_start format YYYY-MM-DD
        if (!preg_match('/\A20\d{2}-\d{2}-\d{2}\z/', $weekStart)) {
            throw new AIValidationException('Invalid period_start format.', ['period_start' => ['code' => 'INVALID_DATE']]);
        }

        $locale = $options['locale'] ?? 'en';
        if (!in_array($locale, ['en', 'fa'], true)) {
            $locale = 'en';
        }

        $trades = $options['trades'] ?? [];
        if ($trades === []) {
            throw new AIValidationException('Trades required for weekly report.', ['trades' => ['code' => 'REQUIRED']]);
        }

        if (count($trades) > self::MAX_TRADES) {
            throw new AIValidationException('Too many trades for report.', ['trades' => ['code' => 'TOO_MANY']]);
        }

        $jsonCheck = json_encode($trades);
        if ($jsonCheck !== false && strlen($jsonCheck) > self::MAX_JSON_BYTES) {
            throw new AIValidationException('Trades payload too large.', ['trades' => ['code' => 'PAYLOAD_TOO_LARGE']]);
        }

        $periodEnd = $options['period_end'] ?? date('Y-m-d', strtotime($weekStart . ' +6 days'));
        if (!preg_match('/\A20\d{2}-\d{2}-\d{2}\z/', $periodEnd)) {
            $periodEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
        }

        // Duplicate prevention: check existing report for same period/locale
        $existing = $this->repository->findForPeriod($userId, $weekStart, $periodEnd, $locale);
        if ($existing !== null) {
            // Return existing as AIResponseDTO for idempotency
            $content = $existing['content'] ?? '{}';
            if (is_string($content)) {
                $content = json_decode($content, true) ?: ['raw' => $content];
            }
            // For backward compat, if content is string, keep as is
            $contentStr = is_array($content) ? json_encode($content) : (string) $content;
            return new \Velora\AI\DTOs\AIResponseDTO(
                content: $contentStr,
                provider: 'cache',
                model: 'cache',
                latencyMs: 0,
                tokensUsed: 0,
                confidence: 0.9,
                status: 'success',
                metadata: ['cached' => true, 'report_id' => $existing['id'] ?? 0],
            );
        }

        // Sanitize trades (same as analyzer)
        $sanitizedTrades = array_map(function (array $t): array {
            $symbol = $t['symbol'] ?? null;
            if (is_string($symbol)) {
                $symbol = strtoupper(trim($symbol));
                $symbol = preg_replace('/[^A-Z0-9\/\.\-_]/', '', $symbol);
                if (strlen($symbol) > 32) $symbol = substr($symbol, 0, 32);
            }
            $side = $t['direction'] ?? $t['side'] ?? null;
            if (is_string($side)) {
                $side = strtolower(trim($side));
                if (!in_array($side, ['buy','sell'], true)) $side = null;
            }
            return [
                'symbol' => $symbol,
                'side' => $side,
                'pnl' => $t['profit_loss'] ?? $t['pnl'] ?? null,
                'open_time' => $t['open_time'] ?? null,
                'close_time' => $t['close_time'] ?? null,
            ];
        }, $trades);

        // First get analysis (uses Analysis module)
        $analysisResponse = $this->analyzer->analyze($userId, $sanitizedTrades, [
            'locale' => $locale,
            'deadline' => $options['deadline'] ?? (microtime(true) + 20),
            'timeframe' => 'weekly_' . $weekStart,
        ]);

        $prompt = '';
        try {
            $prompt = PromptManager::getWithVars('weekly_report', [
                'week_start' => $weekStart,
                'week_end' => $periodEnd,
                'locale' => $locale,
                'analysis' => $analysisResponse->content,
                'trades_count' => (string) count($sanitizedTrades),
            ], 'v1', $locale);
        } catch (\Throwable $e) {
            $prompt = PromptManager::get('weekly_report', 'v1', $locale);
        }

        $deadline = $options['deadline'] ?? (microtime(true) + 25);

        $response = $this->manager->generate($prompt, [
            'trades' => $sanitizedTrades,
            'analysis' => $analysisResponse->content,
            'feature' => 'weekly_report',
            'user_id' => $userId,
            'locale' => $locale,
            'period_start' => $weekStart,
            'period_end' => $periodEnd,
        ], [
            'deadline' => $deadline,
            'feature' => 'weekly_report',
            'user_id' => $userId,
            'capability' => 'reports',
            'responseMimeType' => 'application/json',
        ]);

        // Save report (best effort, ownership validated via user_id)
        try {
            $content = json_decode($response->content, true) ?: ['raw' => $response->content];
            // Ensure structured JSON has required fields
            $structured = [
                'summary' => $content['summary'] ?? '',
                'strengths' => $content['strengths'] ?? [],
                'mistakes' => $content['mistakes'] ?? $content['weaknesses'] ?? [],
                'risk_behavior' => $content['risk_behavior'] ?? $content['riskScore'] ?? 0.0,
                'suggestions' => $content['suggestions'] ?? $content['recommendations'] ?? [],
            ];
            $dto = new ReportDTO(
                userId: $userId,
                periodStart: $weekStart,
                periodEnd: $periodEnd,
                locale: $locale,
                content: $structured,
                provider: $response->provider,
                model: $response->model,
                confidence: $response->confidence,
            );
            $this->repository->create($dto);
        } catch (\Throwable $e) {
            // Best effort
        }

        return $response;
    }

    public function generateMonthly(int $userId, string $monthStart, array $options = []): \Velora\AI\DTOs\AIResponseDTO
    {
        $this->featureGuard->requireEnabled('ai_weekly_report', $userId);
        $options['period_end'] = date('Y-m-t', strtotime($monthStart));
        return $this->generateWeekly($userId, $monthStart, $options);
    }

    public function isEnabled(int $userId): bool
    {
        return $this->featureGuard->isEnabled('ai_weekly_report', $userId);
    }

    public function generateWeeklyReport(int $userId, string $weekStart, string $locale = 'en', array $trades = []): ReportDTO
    {
        $response = $this->generateWeekly($userId, $weekStart, ['locale' => $locale, 'trades' => $trades]);
        $content = json_decode($response->content, true) ?: ['raw' => $response->content];
        return new ReportDTO(
            userId: $userId,
            periodStart: $weekStart,
            periodEnd: date('Y-m-d', strtotime($weekStart . ' +6 days')),
            locale: $locale,
            content: $content,
            provider: $response->provider,
            model: $response->model,
            confidence: $response->confidence,
        );
    }
}
