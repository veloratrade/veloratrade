<?php

declare(strict_types=1);

namespace Velora\Trades;

use Velora\AI\Exceptions\AIConsentRequiredException;
use Velora\AI\Exceptions\AIException;
use Velora\AI\Extraction\ExtractedTradeData;
use Velora\AI\Extraction\ScreenshotExtractor;
use Velora\AI\Providers\TesseractProvider;
use Velora\AI\Repositories\AIAuditLogRepository;
use Velora\AI\Repositories\AIExtractionRepository;
use Velora\AI\Repositories\AIFeatureFlagRepository;
use Velora\AI\Services\AIManager;
use Velora\Core\RateLimiter;
use Velora\Core\Request;
use Velora\Core\Response;

/**
 * Screenshot extraction controller — now uses AI module with Tesseract fallback.
 * Keeps existing rate limiter, file validation, security checks, timeout limits.
 * Backward compatible: returns engine, texts, times plus new extraction field.
 */
final class ScreenshotExtractController
{
    private const MAX_IMAGES = 4;
    private const MAX_IMAGE_BYTES = 8_388_608;
    private const MAX_TOTAL_BYTES = 16_777_216;
    private const MAX_PIXELS = 12_000_000;
    private const REQUEST_DEADLINE_SECONDS = 30.0;

    private float $deadline = 0.0;

    public function extract(Request $request): never
    {
        // Keep existing rate limiter — fail closed
        $userId = (int) ($request->attributes['user_id'] ?? 0);
        RateLimiter::hit('screenshot-ocr-user-' . $userId, 8, 300);

        // TASK GROUP 2: Enforce feature flag for screenshot extraction
        try {
            $flagRepo = new AIFeatureFlagRepository();
            if (!$flagRepo->isEnabled('ai_screenshot_extraction', $userId)) {
                Response::error('AI screenshot extraction is disabled.', 403, 'AI_FEATURE_DISABLED', null, 'errors.ai.featureDisabled');
            }
        } catch (\Velora\Core\Exceptions\ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // If flag table missing, fail open for extraction (backward compat) — log only
            error_log('[VELORA_AI_FLAGS] check failed, fail-open for extraction: ' . $e->getMessage());
        }

        $images = $request->body['images'] ?? null;
        if (!is_array($images) || $images === []) {
            Response::error('images[] is required.', 422, 'VALIDATION_FAILED');
        }
        if (count($images) > self::MAX_IMAGES || array_keys($images) !== range(0, count($images) - 1)) {
            Response::error('Maximum 4 screenshots per request.', 422, 'VALIDATION_FAILED');
        }

        $decoded = [];
        $totalBytes = 0;
        foreach ($images as $dataUrl) {
            if (!is_string($dataUrl)) {
                Response::error('Invalid image payload.', 422, 'VALIDATION_FAILED');
            }
            $raw = $this->decodeAndValidateImage($dataUrl);
            $totalBytes += strlen($raw);
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                Response::error('Combined image payload is too large.', 422, 'VALIDATION_FAILED');
            }
            $decoded[] = $raw;
        }

        $this->deadline = microtime(true) + self::REQUEST_DEADLINE_SECONDS;

        // Image hash for dedup cache (first image)
        $imageHash = hash('sha256', $decoded[0]);

        // Try AI extraction via new module
        $aiManager = new AIManager();
        $screenshotExtractor = new ScreenshotExtractor($aiManager);

        $extractionData = null;
        $providerName = 'tesseract';
        $latencyMs = 0;
        $status = 'success';
        $errorCode = null;
        $aiException = null;

        $start = microtime(true);
        try {
            // Check dedup cache first (if table exists)
            $cached = $this->tryFindCachedExtraction($imageHash, $userId);
            if ($cached !== null) {
                $extractionData = ExtractedTradeData::fromArray(
                    json_decode($cached['final_result'] ?? '{}', true) ?: [],
                    $cached['provider'] ?? 'cache',
                    (float) ($cached['confidence'] ?? 0.0),
                );
                $providerName = $cached['provider'] ?? 'cache';
                $status = 'success';
            } else {
                $extracted = $screenshotExtractor->extractMultiple($decoded, $this->deadline, $userId);
                $extractionData = $extracted;
                $providerName = $extracted->provider;
            }
        } catch (AIConsentRequiredException $e) {
            // Privacy: consent required for external AI — try Tesseract fallback if available, else 403
            $tesseract = new TesseractProvider();
            if ($tesseract->isAvailable()) {
                try {
                    $fallback = $tesseract->extract($decoded[0], $this->deadline);
                    $extractionData = $fallback;
                    $providerName = 'tesseract';
                    $status = 'fallback';
                    $errorCode = 'AI_CONSENT_REQUIRED';
                } catch (\Throwable $e2) {
                    Response::error('AI consent required for external processing.', 403, 'AI_CONSENT_REQUIRED', null, 'errors.ai.consentRequired');
                }
            } else {
                Response::error('AI consent required for external processing.', 403, 'AI_CONSENT_REQUIRED', null, 'errors.ai.consentRequired');
            }
        } catch (AIException $e) {
            $aiException = $e;
            $status = 'failed';
            $errorCode = $e->errorCode();
            // Fallback already attempted inside AIManager, if still fails, we return tesseract texts only
            $extractionData = new ExtractedTradeData(
                provider: 'tesseract',
                confidence: 0.0,
            );
            $providerName = 'tesseract';
        } catch (\Throwable $e) {
            // Unexpected error — never expose details
            error_log(sprintf('[VELORA_AI_EXTRACTION] unexpected error user=%d', $userId));
            $status = 'failed';
            $errorCode = 'INTERNAL_ERROR';
            $extractionData = new ExtractedTradeData(provider: 'tesseract', confidence: 0.0);
        }
        $latencyMs = (int) ((microtime(true) - $start) * 1000);

        // For backward compat, always produce OCR texts via TesseractProvider
        $texts = [];
        $times = ['openTime' => '', 'closeTime' => ''];
        try {
            $tesseractProvider = new TesseractProvider();
            if ($tesseractProvider->isAvailable()) {
                foreach ($decoded as $raw) {
                    if (microtime(true) >= $this->deadline) {
                        break;
                    }
                    $ocrData = $tesseractProvider->extract($raw, $this->deadline);
                    $texts[] = $ocrData->rawText ?? '';
                    // Use times from first image only
                    if ($times['openTime'] === '' && $ocrData->rawResponse['times'] ?? null) {
                        $times = $ocrData->rawResponse['times'];
                    }
                }
                // If times still empty, try readTimesFromFirstImage via provider's internal method
                // The TesseractProvider already includes times in rawResponse
                if ($times['openTime'] === '' && isset($extractionData->rawResponse['times'])) {
                    $times = $extractionData->rawResponse['times'];
                }
            }
        } catch (\Throwable $e) {
            // OCR texts failure should not fail whole request
            $texts = array_fill(0, count($decoded), '');
        }

        // Ensure texts array matches input count for backward compat
        if (count($texts) !== count($decoded)) {
            $texts = array_pad($texts, count($decoded), '');
        }

        // If times still empty, try to get from extraction
        if (($times['openTime'] === '' && $times['closeTime'] === '') && $extractionData !== null) {
            $times = [
                'openTime' => $extractionData->openTime ?? '',
                'closeTime' => $extractionData->closeTime ?? '',
            ];
        }

        // Save to ai_extractions if possible (best effort, don't fail request)
        $this->trySaveExtraction(
            $userId,
            $providerName,
            $imageHash,
            $extractionData,
            $latencyMs,
            $status,
            $errorCode,
        );

        // P0/P1: Audit logging — only image hash, never raw image
        $this->tryAuditLog($userId, $providerName, $imageHash, 'extraction');

        // Backward compatible response + new extraction field
        $response = [
            'engine' => $providerName === 'gemini' ? 'gemini-vision' : 'tesseract-system',
            'provider' => $providerName,
            'texts' => $texts,
            'times' => $times,
            'extraction' => $extractionData?->toArray() ?? [],
            'data' => $extractionData?->toArray() ?? [], // alias for new clients
            'confidence' => $extractionData?->confidence ?? 0.0,
        ];

        // If AI failed but we have fallback, still return 200 with low confidence
        // Only return error if both AI and Tesseract failed and texts empty
        if ($status === 'failed' && $aiException !== null) {
            // If Tesseract also unavailable, return 501
            $tesseract = new TesseractProvider();
            if (!$tesseract->isAvailable()) {
                Response::error('OCR engine is not available on this host.', 501, 'OCR_UNAVAILABLE');
            }
            // Otherwise return fallback result with warning status
            $response['warning'] = 'AI provider failed, used fallback';
        }

        Response::json($response);
    }

    private function decodeAndValidateImage(string $dataUrl): string
    {
        $comma = strpos($dataUrl, ',');
        if ($comma === false) {
            Response::error('Invalid image payload.', 422, 'VALIDATION_FAILED');
        }
        $header = substr($dataUrl, 0, $comma + 1);
        $declaredTypes = [
            'data:image/png;base64,' => IMAGETYPE_PNG,
            'data:image/jpeg;base64,' => IMAGETYPE_JPEG,
            'data:image/webp;base64,' => IMAGETYPE_WEBP,
        ];
        if (!isset($declaredTypes[$header])) {
            Response::error('Only PNG, JPEG, and WebP screenshots are accepted.', 422, 'VALIDATION_FAILED');
        }

        $encoded = substr($dataUrl, $comma + 1);
        if ($encoded === '' || strlen($encoded) > (int) ceil(self::MAX_IMAGE_BYTES * 4 / 3) + 4) {
            Response::error('Image is too large.', 422, 'VALIDATION_FAILED');
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || $raw === '' || strlen($raw) > self::MAX_IMAGE_BYTES) {
            Response::error('Invalid image payload.', 422, 'VALIDATION_FAILED');
        }

        $info = @getimagesizefromstring($raw);
        if ($info === false || (int) ($info[2] ?? 0) !== $declaredTypes[$header]) {
            Response::error('Image content does not match its declared type.', 422, 'VALIDATION_FAILED');
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 32 || $height < 32 || $width > 6000 || $height > 6000
            || ($width * $height) > self::MAX_PIXELS) {
            Response::error('Image dimensions are not supported.', 422, 'VALIDATION_FAILED');
        }

        return $raw;
    }

    /**
     * Best-effort cache lookup — never throws.
     */
    private function tryFindCachedExtraction(string $hash, int $userId): ?array
    {
        try {
            $repo = new AIExtractionRepository();
            return $repo->findByHash($hash, $userId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Best-effort save — never throws, never exposes errors.
     */
    private function trySaveExtraction(
        int $userId,
        string $provider,
        string $hash,
        ?ExtractedTradeData $data,
        int $latencyMs,
        string $status,
        ?string $errorCode,
    ): void {
        try {
            $repo = new AIExtractionRepository();
            $repo->create([
                'user_id' => $userId,
                'provider' => $provider,
                'image_hash' => $hash,
                'original_result' => $data?->rawResponse ?? [],
                'final_result' => $data?->toArray() ?? [],
                'confidence' => $data?->confidence ?? 0.0,
                'latency_ms' => $latencyMs,
                'status' => $status,
                'error_code' => $errorCode,
            ]);
        } catch (\Throwable $e) {
            // Silently ignore — table may not exist yet in dev, or DB unavailable
            error_log('[VELORA_AI] failed to save extraction: ' . $e->getMessage());
        }
    }

    private function tryAuditLog(int $userId, string $provider, string $hash, string $action): void
    {
        try {
            $repo = new AIAuditLogRepository();
            $repo->log($userId, 'extraction', $provider, $hash, $action);
        } catch (\Throwable $e) {
            // Best effort
        }
    }
}
