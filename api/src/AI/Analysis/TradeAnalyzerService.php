<?php

declare(strict_types=1);

namespace Velora\AI\Analysis;

use Velora\AI\Exceptions\AIValidationException;
use Velora\AI\Prompts\PromptManager;
use Velora\AI\Services\AIFeatureGuard;
use Velora\AI\Services\AIManager;

/**
 * Trade Analyzer Service — P1 implementation.
 * MUST NOT query trades table — receives TradeDataDTO[] from caller.
 * Uses AIManager::generate() + PromptManager.
 */
final class TradeAnalyzerService implements TradeAnalyzerInterface
{
    private const MAX_TRADES = 100;
    private const MAX_JSON_BYTES = 200_000; // 200KB max for trades JSON

    private AIManager $manager;
    private TradeAnalysisRepository $repository;
    private AIFeatureGuard $featureGuard;

    public function __construct(?AIManager $manager = null, ?TradeAnalysisRepository $repository = null, ?AIFeatureGuard $featureGuard = null)
    {
        $this->manager = $manager ?? new AIManager();
        $this->repository = $repository ?? new TradeAnalysisRepository();
        $this->featureGuard = $featureGuard ?? new AIFeatureGuard();
    }

    public function analyze(int $userId, array $trades, array $options = []): \Velora\AI\DTOs\AIResponseDTO
    {
        $this->featureGuard->requireEnabled('ai_trade_analysis', $userId);

        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user_id');
        }
        if ($trades === []) {
            throw new AIValidationException('Trades array required.', ['trades' => ['code' => 'REQUIRED']]);
        }

        // Validation: max trade count
        if (count($trades) > self::MAX_TRADES) {
            throw new AIValidationException('Too many trades for analysis.', [
                'trades' => ['code' => 'TOO_MANY', 'messageKey' => 'errors.ai.validation.tooManyTrades']
            ]);
        }

        // Prevent huge JSON payloads
        $jsonCheck = json_encode($trades);
        if ($jsonCheck !== false && strlen($jsonCheck) > self::MAX_JSON_BYTES) {
            throw new AIValidationException('Trades payload too large.', [
                'trades' => ['code' => 'PAYLOAD_TOO_LARGE']
            ]);
        }

        // Sanitize trades — only allow specific fields, never raw DB rows
        $sanitizedTrades = array_map(function (array $t): array {
            // Sanitize user generated fields: strategy_tag, notes, symbol
            $symbol = $t['symbol'] ?? null;
            if (is_string($symbol)) {
                $symbol = strtoupper(trim($symbol));
                $symbol = preg_replace('/[^A-Z0-9\/\.\-_]/', '', $symbol);
                if (strlen($symbol) > 32) {
                    $symbol = substr($symbol, 0, 32);
                }
            }

            $side = $t['direction'] ?? $t['side'] ?? null;
            if (is_string($side)) {
                $side = strtolower(trim($side));
                if (!in_array($side, ['buy', 'sell'], true)) {
                    $side = null;
                }
            }

            // Sanitize numeric fields
            $sanitizeNumeric = function ($val): ?string {
                if ($val === null) return null;
                if (is_numeric($val)) return (string) $val;
                $cleaned = preg_replace('/[^0-9\.\-]/', '', (string) $val);
                return is_numeric($cleaned) ? $cleaned : null;
            };

            return [
                'symbol' => $symbol,
                'side' => $side,
                'entry' => $sanitizeNumeric($t['entry_price'] ?? $t['entry'] ?? null),
                'exit' => $sanitizeNumeric($t['exit_price'] ?? $t['exit'] ?? null),
                'pnl' => $sanitizeNumeric($t['profit_loss'] ?? $t['pnl'] ?? null),
                'duration' => $this->calculateDuration($t),
                'riskReward' => $sanitizeNumeric($t['r_multiple'] ?? $t['riskReward'] ?? null),
            ];
        }, $trades);

        $locale = $options['locale'] ?? 'en';
        if (!in_array($locale, ['en', 'fa'], true)) {
            $locale = 'en';
        }

        $prompt = '';
        try {
            $prompt = PromptManager::getWithVars('trade_analysis', [
                'trades' => json_encode($sanitizedTrades, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'locale' => $locale,
            ], 'v1', $locale);
        } catch (\Throwable $e) {
            $prompt = PromptManager::get('trade_analysis', 'v1', 'en');
        }

        $deadline = $options['deadline'] ?? (microtime(true) + 20);

        $response = $this->manager->generate($prompt, [
            'trades' => $sanitizedTrades,
            'feature' => 'analysis',
            'user_id' => $userId,
            'locale' => $locale,
            'timeframe' => $options['timeframe'] ?? 'last_100',
        ], [
            'deadline' => $deadline,
            'feature' => 'analysis',
            'user_id' => $userId,
            'capability' => 'analysis',
            'responseMimeType' => 'application/json',
        ]);

        // Save analysis result (best effort)
        try {
            $json = json_decode($response->content, true);
            if (is_array($json)) {
                $this->repository->create($userId, $json, $response->provider, $response->model, $response->confidence);
            }
        } catch (\Throwable $e) {
        }

        return $response;
    }

    public function analyzeToDTO(int $userId, array $trades, array $options = []): AnalysisResultDTO
    {
        $response = $this->analyze($userId, $trades, $options);
        $data = json_decode($response->content, true);
        if (!is_array($data)) {
            if (preg_match('/\{.*\}/s', $response->content, $m)) {
                $data = json_decode($m[0], true);
            }
        }
        if (!is_array($data)) {
            $data = ['summary' => $response->content];
        }

        return AnalysisResultDTO::fromArray($data, $response->provider, $response->model, $response->confidence, $response->rawResponse);
    }

    public function isEnabled(int $userId): bool
    {
        return $this->featureGuard->isEnabled('ai_trade_analysis', $userId);
    }

    private function calculateDuration(array $trade): ?string
    {
        $open = $trade['open_time'] ?? $trade['openTime'] ?? null;
        $close = $trade['close_time'] ?? $trade['closeTime'] ?? null;
        if (!$open || !$close) {
            return null;
        }
        try {
            $openDt = new \DateTime($open);
            $closeDt = new \DateTime($close);
            $diff = $openDt->diff($closeDt);
            return $diff->format('%h hours %i minutes');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
