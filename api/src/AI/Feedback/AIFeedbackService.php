<?php

declare(strict_types=1);

namespace Velora\AI\Feedback;

use Velora\AI\Exceptions\AIValidationException;
use Velora\AI\Repositories\AIExtractionRepository;

/**
 * Service for AI feedback — P1 implementation with whitelist + ownership validation.
 * Never stores screenshots, never stores raw API responses, only fields.
 */
final class AIFeedbackService implements AIFeedbackServiceInterface
{
    private const ALLOWED_FIELDS = ['entry', 'exit', 'symbol', 'side', 'lot', 'sl', 'tp', 'pnl', 'openTime', 'closeTime', 'entry_price', 'exit_price', 'volume', 'stop_loss', 'take_profit', 'profit_loss'];

    private AIFeedbackRepository $repository;
    private AIExtractionRepository $extractionRepo;

    public function __construct(?AIFeedbackRepository $repository = null, ?AIExtractionRepository $extractionRepo = null)
    {
        $this->repository = $repository ?? new AIFeedbackRepository();
        $this->extractionRepo = $extractionRepo ?? new AIExtractionRepository();
    }

    public function storeCorrection(int $userId, int $extractionId, array $original, array $corrected): int
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user_id');
        }

        // Ownership validation: user must own extraction_id
        if ($extractionId > 0) {
            $owned = $this->extractionRepo->findOwned($extractionId, $userId);
            if ($owned === null) {
                throw new AIValidationException('Extraction not found or not owned.', [
                    'extraction_id' => ['code' => 'NOT_OWNED']
                ]);
            }
        }

        // Whitelist validation
        $allKeys = array_unique(array_merge(array_keys($original), array_keys($corrected)));
        foreach ($allKeys as $key) {
            if (!in_array($key, self::ALLOWED_FIELDS, true)) {
                throw new AIValidationException('Field not allowed for correction.', [
                    $key => ['code' => 'FIELD_NOT_ALLOWED', 'messageKey' => 'errors.ai.validation.fieldNotAllowed']
                ]);
            }
        }

        // Calculate changed fields
        $changedFields = [];
        foreach ($allKeys as $key) {
            $origVal = $original[$key] ?? null;
            $corrVal = $corrected[$key] ?? null;
            if ($origVal !== $corrVal) {
                $changedFields[] = $key;
            }
        }

        if ($changedFields === []) {
            throw new AIValidationException('No changes detected.', ['changed_fields' => ['code' => 'NO_CHANGES']]);
        }

        // Never store screenshots or raw API responses
        $this->assertNoImageData($original);
        $this->assertNoImageData($corrected);
        $this->assertNoRawResponse($original);
        $this->assertNoRawResponse($corrected);

        return $this->repository->createFeedback(
            $userId,
            $extractionId > 0 ? $extractionId : null,
            $original,
            $corrected,
            $changedFields,
        );
    }

    public function createFeedback(int $userId, int $extractionId, array $originalResult, array $correctedResult, array $changedFields): int
    {
        // Whitelist check for changedFields param
        foreach ($changedFields as $field) {
            if (!in_array($field, self::ALLOWED_FIELDS, true)) {
                throw new AIValidationException('Field not allowed.', [$field => ['code' => 'FIELD_NOT_ALLOWED']]);
            }
        }

        // Ownership validation
        if ($extractionId > 0) {
            $owned = $this->extractionRepo->findOwned($extractionId, $userId);
            if ($owned === null) {
                throw new AIValidationException('Extraction not owned.', ['extraction_id' => ['code' => 'NOT_OWNED']]);
            }
        }

        $this->assertNoImageData($originalResult);
        $this->assertNoImageData($correctedResult);

        return $this->repository->createFeedback($userId, $extractionId, $originalResult, $correctedResult, $changedFields);
    }

    public function findUserFeedback(int $userId): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user_id');
        }
        return $this->repository->findUserFeedback($userId);
    }

    public function statistics(): array
    {
        return $this->repository->statistics();
    }

    public function getAccuracyStats(string $provider, int $days = 7): array
    {
        return $this->repository->statistics($days);
    }

    public static function getAllowedFields(): array
    {
        return self::ALLOWED_FIELDS;
    }

    private function assertNoImageData(array $data): void
    {
        $json = json_encode($data);
        if ($json === false) {
            return;
        }
        if (stripos($json, 'data:image') !== false || strlen($json) > 100000) {
            throw new AIValidationException('Feedback must not contain image data.', ['image' => ['code' => 'IMAGE_NOT_ALLOWED']]);
        }
        // Check for base64 that looks like image (long string >10k with only base64 chars)
        if (preg_match('/[A-Za-z0-9+\/]{10000,}/', $json)) {
            throw new AIValidationException('Feedback must not contain raw image data.', ['image' => ['code' => 'BASE64_NOT_ALLOWED']]);
        }
    }

    private function assertNoRawResponse(array $data): void
    {
        // Prevent storing raw provider responses containing candidates, usage, etc.
        $forbiddenKeys = ['candidates', 'usage', 'rawResponse', 'inline_data'];
        foreach ($forbiddenKeys as $k) {
            if (array_key_exists($k, $data)) {
                throw new AIValidationException('Raw API response not allowed.', [$k => ['code' => 'RAW_NOT_ALLOWED']]);
            }
        }
    }
}
