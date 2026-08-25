<?php

declare(strict_types=1);

namespace Velora\AI\Services;

use Velora\AI\Repositories\AIFeatureFlagRepository;
use Velora\Core\Exceptions\ForbiddenException;

/**
 * Central guard for AI feature flags.
 * Must be used before every AI feature to enforce gradual rollout.
 * Returns 403 AI_FEATURE_DISABLED if disabled.
 */
final class AIFeatureGuard
{
    private AIFeatureFlagRepository $flagRepo;

    public function __construct(?AIFeatureFlagRepository $flagRepo = null)
    {
        $this->flagRepo = $flagRepo ?? new AIFeatureFlagRepository();
    }

    /**
     * Check if feature is enabled, throw 403 if not.
     *
     * @param string $featureName e.g. ai_screenshot_extraction, ai_trade_analysis
     * @param int|null $userId For deterministic rollout
     * @throws ForbiddenException
     */
    public function requireEnabled(string $featureName, ?int $userId = null): void
    {
        if (!$this->isEnabled($featureName, $userId)) {
            throw new ForbiddenException(
                'AI feature disabled: ' . $featureName,
                'AI_FEATURE_DISABLED',
                'errors.ai.featureDisabled',
            );
        }
    }

    public function isEnabled(string $featureName, ?int $userId = null): bool
    {
        return $this->flagRepo->isEnabled($featureName, $userId);
    }

    /**
     * Check multiple flags at once.
     *
     * @param string[] $features
     * @return array<string,bool>
     */
    public function checkMultiple(array $features, ?int $userId = null): array
    {
        $result = [];
        foreach ($features as $f) {
            $result[$f] = $this->isEnabled($f, $userId);
        }
        return $result;
    }
}
