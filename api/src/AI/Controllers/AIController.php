<?php

declare(strict_types=1);

namespace Velora\AI\Controllers;

use Velora\AI\Analysis\TradeAnalyzerService;
use Velora\AI\Feedback\AIFeedbackService;
use Velora\AI\Reports\WeeklyReportService;
use Velora\AI\Repositories\AIAuditLogRepository;
use Velora\AI\Services\AIFeatureGuard;
use Velora\Auth\UserRepository;
use Velora\Core\RateLimiter;
use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Core\Validation;
use Velora\Trades\TradeResolver;

/**
 * AI Controller — P1 endpoints for analysis, reports, feedback.
 *
 * TRUST BOUNDARY: the controller NEVER trusts client-supplied trade objects.
 * Clients send trade_ids[]; the controller resolves them server-side through
 * TradeResolver (ownership enforced), then passes sanitized payloads to the
 * analysis layer. Model output is whitelisted before it is returned/persisted.
 */
final class AIController
{
    private const MAX_TRADES_ANALYSIS = 100;
    private const MAX_TRADES_REPORT = 200;

    /** Output whitelists — unknown model fields are dropped, never echoed. */
    private const ANALYSIS_OUTPUT_FIELDS = ['summary', 'strengths', 'weaknesses', 'recommendations', 'risk_score', 'riskScore', 'confidence'];
    private const REPORT_OUTPUT_FIELDS = ['summary', 'strengths', 'mistakes', 'weaknesses', 'risk_behavior', 'suggestions', 'recommendations', 'confidence'];

    public function __construct(
        private readonly TradeAnalyzerService $analyzer = new TradeAnalyzerService(),
        private readonly WeeklyReportService $reportService = new WeeklyReportService(),
        private readonly AIFeedbackService $feedbackService = new AIFeedbackService(),
        private readonly AIFeatureGuard $featureGuard = new AIFeatureGuard(),
        private readonly AIAuditLogRepository $auditRepo = new AIAuditLogRepository(),
        private readonly TradeResolver $tradeResolver = new TradeResolver(),
        private readonly UserRepository $userRepository = new UserRepository(),
    ) {
    }

    /**
     * G8 — AI user-facing prose must follow the canonical locale contract.
     * Resolution order: validated client locale → persisted canonical user
     * locale (users.locale from the canonical locale system) → 'en' fallback.
     * A client that omits or sends an invalid locale must never silently get
     * English while the user's persisted locale is fa, and an unvalidated
     * locale value is never echoed back.
     */
    private function resolveAiLocale(int $userId, ?string $bodyLocale): string
    {
        if (is_string($bodyLocale) && in_array(strtolower(trim($bodyLocale)), ['fa', 'en'], true)) {
            return strtolower(trim($bodyLocale));
        }
        $user = $this->userRepository->findById($userId);
        $locale = is_array($user) ? strtolower(trim((string) ($user['locale'] ?? ''))) : '';
        return in_array($locale, ['fa', 'en'], true) ? $locale : 'en';
    }

    /**
     * POST /api/v1/ai/analyze-trades
     * Body: { trade_ids: [...], locale: en/fa, timeframe: last_100 }
     */
    public function analyzeTrades(Request $request): never
    {
        $userId = (int) ($request->attributes['user_id'] ?? 0);
        RateLimiter::hit('ai-analyze-user-' . $userId, 10, 3600); // 10 per hour

        $this->featureGuard->requireEnabled('ai_trade_analysis', $userId);

        // Reject the insecure client-supplied trades[] contract explicitly.
        if (array_key_exists('trades', $request->body) && !array_key_exists('trade_ids', $request->body)) {
            Response::error('Client-supplied trades are not accepted; send trade_ids[] instead.', 422, 'VALIDATION_FAILED', null, 'errors.ai.validation.tradeIdsRequired');
        }

        Validation::assert($request->body, [
            'trade_ids' => 'required|array',
            'locale' => 'string|max:10',
            'timeframe' => 'string|max:32',
        ]);

        $tradeIds = $request->body['trade_ids'] ?? [];
        if (!is_array($tradeIds) || count($tradeIds) === 0) {
            Response::error('trade_ids[] is required.', 422, 'VALIDATION_FAILED');
        }
        if (count($tradeIds) > self::MAX_TRADES_ANALYSIS) {
            Response::error('Too many trade ids for analysis (max 100).', 422, 'VALIDATION_FAILED');
        }

        // Resolve server-side with ownership enforcement (never trust the client).
        $trades = $this->tradeResolver->resolveOwned($userId, $tradeIds, self::MAX_TRADES_ANALYSIS);
        if ($trades === []) {
            Response::error('No owned trades found for the provided ids.', 422, 'VALIDATION_FAILED', null, 'errors.ai.validation.noOwnedTrades');
        }

        $locale = $this->resolveAiLocale($userId, $request->body['locale'] ?? null);

        $timeframe = trim((string) ($request->body['timeframe'] ?? 'last_100'));
        if (strlen($timeframe) > 32) {
            $timeframe = 'last_100';
        }

        // Audit logging (no raw trades content, only count and hash)
        try {
            $tradesHash = hash('sha256', json_encode($trades));
            $this->auditRepo->log($userId, 'analysis', 'gemini', $tradesHash, 'analyze_trades');
        } catch (\Throwable $e) {
        }

        try {
            $response = $this->analyzer->analyze($userId, $trades, [
                'locale' => $locale,
                'timeframe' => $timeframe,
                'deadline' => microtime(true) + 20,
            ]);

            $data = json_decode($response->content, true);
            if (!is_array($data)) {
                if (preg_match('/\{.*\}/s', $response->content, $m)) {
                    $data = json_decode($m[0], true);
                }
            }
            if (!is_array($data)) {
                $data = ['summary' => $response->content];
            }

            Response::json([
                'analysis' => $this->whitelistOutput($data, self::ANALYSIS_OUTPUT_FIELDS),
                'provider' => $response->provider,
                'model' => $response->model,
                'confidence' => $response->confidence,
                'latency_ms' => $response->latencyMs,
            ]);
        } catch (\Velora\AI\Exceptions\AIException $e) {
            Response::error($e->getMessage(), $e->httpStatus(), $e->errorCode(), $e->details(), $e->messageKey(), $e->params());
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_ANALYZE] failed user=' . $userId);
            Response::error('AI analysis failed.', 502, 'AI_ANALYSIS_FAILED');
        }
    }

    /**
     * POST /api/v1/ai/weekly-report
     * Body: { trade_ids: [...], period_start: YYYY-MM-DD, period_end: YYYY-MM-DD, locale: en/fa }
     */
    public function weeklyReport(Request $request): never
    {
        $userId = (int) ($request->attributes['user_id'] ?? 0);
        RateLimiter::hit('ai-report-user-' . $userId, 5, 3600); // 5 per hour

        $this->featureGuard->requireEnabled('ai_weekly_report', $userId);

        if (array_key_exists('trades', $request->body) && !array_key_exists('trade_ids', $request->body)) {
            Response::error('Client-supplied trades are not accepted; send trade_ids[] instead.', 422, 'VALIDATION_FAILED', null, 'errors.ai.validation.tradeIdsRequired');
        }

        Validation::assert($request->body, [
            'trade_ids' => 'required|array',
            'period_start' => 'required|string|max:20',
            'locale' => 'string|max:10',
        ]);

        $tradeIds = $request->body['trade_ids'] ?? [];
        if (count($tradeIds) > self::MAX_TRADES_REPORT) {
            Response::error('Too many trade ids for report (max 200).', 422, 'VALIDATION_FAILED');
        }

        $trades = $this->tradeResolver->resolveOwned($userId, $tradeIds, self::MAX_TRADES_REPORT);
        if ($trades === []) {
            Response::error('No owned trades found for the provided ids.', 422, 'VALIDATION_FAILED', null, 'errors.ai.validation.noOwnedTrades');
        }

        $periodStart = trim((string) $request->body['period_start']);
        if (!preg_match('/\A20\d{2}-\d{2}-\d{2}\z/', $periodStart)) {
            Response::error('Invalid period_start format YYYY-MM-DD.', 422, 'VALIDATION_FAILED');
        }

        $periodEnd = trim((string) ($request->body['period_end'] ?? date('Y-m-d', strtotime($periodStart . ' +6 days'))));
        $locale = $this->resolveAiLocale($userId, $request->body['locale'] ?? null);

        try {
            $tradesHash = hash('sha256', json_encode($trades));
            $this->auditRepo->log($userId, 'weekly_report', 'gemini', $tradesHash, 'weekly_report');
        } catch (\Throwable $e) {
        }

        try {
            $response = $this->reportService->generateWeekly($userId, $periodStart, [
                'trades' => $trades,
                'period_end' => $periodEnd,
                'locale' => $locale,
                'deadline' => microtime(true) + 25,
            ]);

            $content = json_decode($response->content, true) ?: ['raw' => $response->content];

            Response::json([
                'report' => $this->whitelistOutput($content, self::REPORT_OUTPUT_FIELDS),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'locale' => $locale,
                'provider' => $response->provider,
                'model' => $response->model,
                'confidence' => $response->confidence,
            ]);
        } catch (\Velora\AI\Exceptions\AIException $e) {
            Response::error($e->getMessage(), $e->httpStatus(), $e->errorCode(), $e->details(), $e->messageKey(), $e->params());
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_REPORT] failed user=' . $userId);
            Response::error('AI report generation failed.', 502, 'AI_REPORT_FAILED');
        }
    }

    /**
     * POST /api/v1/ai/feedback
     * Body: { extraction_id: int, original: {...}, corrected: {...} }
     */
    public function feedback(Request $request): never
    {
        $userId = (int) ($request->attributes['user_id'] ?? 0);
        RateLimiter::hit('ai-feedback-user-' . $userId, 20, 3600); // 20 per hour

        // Feedback uses same flag as extraction for now
        $this->featureGuard->requireEnabled('ai_screenshot_extraction', $userId);

        Validation::assert($request->body, [
            'extraction_id' => 'required|integer',
            'original' => 'required|array',
            'corrected' => 'required|array',
        ]);

        $extractionId = (int) $request->body['extraction_id'];
        $original = $request->body['original'];
        $corrected = $request->body['corrected'];

        if (!is_array($original) || !is_array($corrected)) {
            Response::error('original and corrected must be objects.', 422, 'VALIDATION_FAILED');
        }

        try {
            $hash = hash('sha256', json_encode($corrected));
            $this->auditRepo->log($userId, 'feedback', 'user', $hash, 'feedback');
        } catch (\Throwable $e) {
        }

        try {
            $feedbackId = $this->feedbackService->storeCorrection($userId, $extractionId, $original, $corrected);

            Response::json([
                'feedback_id' => $feedbackId,
                'stored' => true,
                'messageKey' => 'ai.feedbackStored',
            ], 201);
        } catch (\Velora\AI\Exceptions\AIException $e) {
            Response::error($e->getMessage(), $e->httpStatus(), $e->errorCode(), $e->details(), $e->messageKey(), $e->params());
        } catch (\Throwable $e) {
            error_log('[VELORA_AI_FEEDBACK] failed user=' . $userId);
            Response::error('Failed to store feedback.', 500, 'AI_FEEDBACK_FAILED');
        }
    }

    /**
     * Whitelist model output: keep only known fields (strings/arrays/numerics),
     * drop everything else so unknown model fields never reach the client.
     *
     * @param array<string, mixed> $data
     * @param string[] $allowed
     * @return array<string, mixed>
     */
    private function whitelistOutput(array $data, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_string($value)) {
                $out[$key] = $value;
            } elseif (is_array($value)) {
                // String lists only — flatten and filter to strings.
                $list = [];
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $list[] = $item;
                    }
                }
                $out[$key] = $list;
            } elseif (is_int($value) || is_float($value)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
