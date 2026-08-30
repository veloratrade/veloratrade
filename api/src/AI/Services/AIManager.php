<?php

declare(strict_types=1);

namespace Velora\AI\Services;

use Velora\AI\DTOs\AIRequestDTO;
use Velora\AI\DTOs\AIResponseDTO;
use Velora\AI\Exceptions\AIConsentRequiredException;
use Velora\AI\Exceptions\AIException;
use Velora\AI\Exceptions\AIProviderException;
use Velora\AI\Exceptions\AIQuotaExhaustedException;
use Velora\AI\Exceptions\AITimeoutException;
use Velora\AI\Exceptions\AIValidationException;
use Velora\AI\Extraction\ExtractedTradeData;
use Velora\AI\Providers\AIProviderInterface;
use Velora\AI\Providers\GeminiProvider;
use Velora\AI\Providers\TesseractProvider;
use Velora\AI\Services\AIFailureClassifier;
use Velora\AI\Services\FeatureRouter;
use Velora\AI\Services\ProviderCatalog;
use Velora\AI\Repositories\AIProviderLogRepository;
use Velora\AI\Repositories\AIProviderQuotaRepository;
use Velora\AI\Repositories\AIRequestRepository;
use Velora\AI\Security\ImageAnonymizer;
use Velora\Core\Database;

/**
 * Hardened failover manager — atomic quota reservation.
 * Priority: Gemini -> Tesseract.
 * No Redis, simple DB-based quota + logging.
 */
final class AIManager
{
    /** @var AIProviderInterface[] */
    private array $providers;
    /** @var array<string,AIProviderInterface> name => instance (chain lookups) */
    private array $providerMap = [];
    private AIProviderQuotaRepository $quotaRepo;
    private AIProviderLogRepository $logRepo;
    private AIRequestRepository $requestRepo;
    private ?FeatureRouter $router;

    public function __construct(?array $providers = null, ?AIProviderQuotaRepository $quotaRepo = null, ?AIProviderLogRepository $logRepo = null, ?AIRequestRepository $requestRepo = null, ?FeatureRouter $router = null)
    {
        if ($providers === null) {
            // Use registry instead of hardcoded list
            $registry = new AIProviderRegistry();
            $this->providers = $registry->loadEnabledProviders();
        } else {
            $this->providers = $providers;
        }
        foreach ($this->providers as $p) {
            $this->providerMap[$p->getName()] = $p;
        }
        $this->quotaRepo = $quotaRepo ?? new AIProviderQuotaRepository();
        $this->logRepo = $logRepo ?? new AIProviderLogRepository();
        $this->requestRepo = $requestRepo ?? new AIRequestRepository();
        $this->router = $router;
    }

    /**
     * Resolve the ordered provider chain for a feature via FeatureRouter.
     * Unknown/unrouted features keep the legacy environment-driven iteration
     * (identical provider list and order as before v0.9).
     *
     * @return array<int,array<string,mixed>>
     */
    private function chainEntries(string $feature, ?string $capability): array
    {
        if ($this->router === null) {
            $this->router = new FeatureRouter();
        }
        if (in_array($feature, ProviderCatalog::FEATURES, true)) {
            return $this->router->resolveChain($feature, $capability);
        }
        return $this->router->buildEnvDefaultChain($capability);
    }

    /**
     * Provider instance for a chain entry: injected providers win (tests /
     * dependency injection), otherwise the catalog class is instantiated.
     */
    private function providerFor(string $name): ?AIProviderInterface
    {
        if (isset($this->providerMap[$name])) {
            return $this->providerMap[$name];
        }
        $class = ProviderCatalog::providerClass($name);
        if ($class !== null && class_exists($class)) {
            try {
                $instance = new $class();
                if ($instance instanceof AIProviderInterface) {
                    return $this->providerMap[$name] = $instance;
                }
            } catch (\Throwable $e) {
                error_log('[VELORA_AI_MANAGER] failed to instantiate provider ' . $name);
            }
        }
        return null;
    }

    /**
     * Generic generate with atomic quota reservation + consent check + anonymization.
     */
    public function generate(string $prompt, array $context = [], array $options = []): AIResponseDTO
    {
        $deadline = $options['deadline'] ?? (microtime(true) + 10);
        $feature = $options['feature'] ?? $context['feature'] ?? 'generic';
        $userId = $options['user_id'] ?? $context['user_id'] ?? 0;

        $lastException = null;
        $tried = [];

        $chain = $this->chainEntries((string) $feature, isset($options['capability']) ? (string) $options['capability'] : null);
        foreach ($chain as $entry) {
            $provider = $this->providerFor((string) $entry['provider']);
            if ($provider === null) {
                $tried[] = $entry['provider'] . ':not_registered';
                continue;
            }
            $name = $provider->getName();
            $costTier = $provider->getCostTier();
            $routeOverride = isset($entry['route']) && is_string($entry['route']) && $entry['route'] !== '' ? $entry['route'] : null;
            $entryModel = isset($entry['model']) && is_string($entry['model']) && $entry['model'] !== '' ? $entry['model'] : null;
            $fallbackIndex = (int) ($entry['fallback_index'] ?? 0);

            $requiredCap = $options['capability'] ?? null;
            if ($requiredCap !== null && !in_array($requiredCap, $provider->getCapabilities(), true)) {
                $tried[] = $name . ':unsupported_capability';
                continue;
            }

            if (!$provider->isAvailable()) {
                $tried[] = $name . ':not_available';
                $this->logRepo->log($name, 'failed', 0, 'NOT_AVAILABLE', (string) $feature, $entryModel, $routeOverride, $fallbackIndex);
                continue;
            }

            if (microtime(true) >= $deadline) {
                throw new AITimeoutException('Global deadline exceeded', 'manager');
            }

            // Privacy: consent check for external providers (not tesseract)
            if ($name !== 'tesseract' && $userId > 0) {
                if (!$this->hasAIConsent((int) $userId)) {
                    throw new AIConsentRequiredException('AI consent required for external provider ' . $name);
                }
            }

            // Atomic reservation BEFORE external call — fixes race condition
            $reserved = $this->quotaRepo->tryReserveQuota($name);
            if (!$reserved) {
                if (!$this->quotaRepo->hasQuota($name, $costTier)) {
                    $tried[] = $name . ':quota_exhausted';
                    $this->logRepo->log($name, 'quota_exhausted', 0, 'QUOTA_EXHAUSTED');
                    $lastException = new AIQuotaExhaustedException('Quota exhausted for ' . $name, $name);
                    continue;
                }
                $tried[] = $name . ':quota_race_exhausted';
                $this->logRepo->log($name, 'quota_exhausted', 0, 'QUOTA_RACE');
                $lastException = new AIQuotaExhaustedException('Quota race exhausted for ' . $name, $name);
                continue;
            }

            // Privacy: anonymize image before external call — FAIL-CLOSED.
            // If anonymization fails, the original image MUST NOT reach an
            // external provider; skip this provider and let the local fallback
            // (tesseract OCR) handle it instead.
            if ($name !== 'tesseract' && isset($context['imageRaw']) && is_string($context['imageRaw']) && $context['imageRaw'] !== '') {
                if (ImageAnonymizer::shouldAnonymize($context['imageRaw'])) {
                    $anonymized = ImageAnonymizer::anonymize($context['imageRaw']);
                    if ($anonymized === null || $anonymized === '') {
                        $tried[] = $name . ':anonymization_failed';
                        $this->logRepo->log($name, 'failed', 0, 'ANONYMIZATION_FAILED');
                        $lastException = new AIProviderException(
                            'Image anonymization failed; refusing to send the original image to an external provider.',
                            $name,
                        );
                        continue;
                    }
                    $context['imageRaw'] = $anonymized;
                }
            }

            $start = microtime(true);
            try {
                $callOptions = $options;
                if ($routeOverride !== null) {
                    $callOptions['route'] = $routeOverride;
                }
                if ($entryModel !== null && !isset($callOptions['model'])) {
                    $callOptions['model'] = $entryModel;
                }
                $response = $provider->generate($prompt, $context, $callOptions);
                $latency = (int) ((microtime(true) - $start) * 1000);

                $this->logRepo->log($name, 'success', $latency, null, (string) $feature, $entryModel ?? $response->model, $routeOverride, $fallbackIndex);

                // Track request for audit/cost
                try {
                    $reqDto = new AIRequestDTO(
                        userId: (int) $userId,
                        feature: $feature,
                        provider: $name,
                        model: $response->model,
                        prompt: $prompt,
                        promptHash: hash('sha256', $prompt),
                        context: $context,
                        options: $options,
                    );
                    $this->requestRepo->logRequest($reqDto, $response);
                } catch (\Throwable $e) {
                }

                return $response;
            } catch (AIQuotaExhaustedException $e) {
                $latency = (int) ((microtime(true) - $start) * 1000);
                $tried[] = $name . ':quota_exhausted';
                $this->logRepo->log($name, 'quota_exhausted', $latency, $e->errorCode(), (string) $feature, $entryModel, $routeOverride, $fallbackIndex);
                $lastException = $e;
                // Quota was already reserved, but we keep it reserved (counts as attempt) — intentional to prevent retry storm
                continue;
            } catch (AITimeoutException $e) {
                $latency = (int) ((microtime(true) - $start) * 1000);
                $tried[] = $name . ':timeout';
                $this->logRepo->log($name, 'timeout', $latency, $e->errorCode(), (string) $feature, $entryModel, $routeOverride, $fallbackIndex);
                $lastException = $e;
                continue;
            } catch (AIException $e) {
                $latency = (int) ((microtime(true) - $start) * 1000);
                if (AIFailureClassifier::classify($e) === AIFailureClassifier::ABORT) {
                    // Consent, input validation, configuration or application
                    // errors never fall through the chain.
                    $this->logRepo->log($name, 'failed', $latency, $e->errorCode(), (string) $feature, $entryModel, $routeOverride, $fallbackIndex);
                    throw $e;
                }
                $tried[] = $name . ':' . strtolower($e->errorCode() ?? 'failed');
                $this->logRepo->log($name, 'failed', $latency, $e->errorCode(), (string) $feature, $entryModel, $routeOverride, $fallbackIndex);
                $lastException = $e;
                continue;
            }
        }

        if ($lastException !== null) {
            throw $lastException;
        }

        throw new AIProviderException('All providers failed: ' . implode(', ', $tried), 'manager');
    }

    /**
     * Extract with atomic quota reservation + consent + anonymization — backward compatible.
     */
    public function extract(string $imageRaw, float $deadline, int $userId = 0): ExtractedTradeData
    {
        $lastException = null;
        $triedProviders = [];

        $chain = $this->chainEntries('screenshot_extraction', null);
        foreach ($chain as $entry) {
            $provider = $this->providerFor((string) $entry['provider']);
            if ($provider === null) {
                $triedProviders[] = $entry['provider'] . ':not_registered';
                continue;
            }
            $name = $provider->getName();
            $costTier = $provider->getCostTier();
            $routeOverride = isset($entry['route']) && is_string($entry['route']) && $entry['route'] !== '' ? $entry['route'] : null;
            $entryModel = isset($entry['model']) && is_string($entry['model']) && $entry['model'] !== '' ? $entry['model'] : null;
            $fallbackIndex = (int) ($entry['fallback_index'] ?? 0);

            if (!$provider->isAvailable()) {
                $triedProviders[] = $name . ':not_available';
                $this->logRepo->log($name, 'failed', 0, 'NOT_AVAILABLE', 'screenshot_extraction', $entryModel, $routeOverride, $fallbackIndex);
                continue;
            }

            if (microtime(true) >= $deadline) {
                throw new AITimeoutException('Global deadline exceeded before provider.', 'manager');
            }

            // Privacy: consent check for external providers
            if ($name !== 'tesseract' && $userId > 0) {
                if (!$this->hasAIConsent($userId)) {
                    throw new AIConsentRequiredException('AI consent required for external provider ' . $name);
                }
            }

            // Atomic reservation
            $reserved = $this->quotaRepo->tryReserveQuota($name);
            if (!$reserved) {
                if (!$this->quotaRepo->hasQuota($name, $costTier)) {
                    $triedProviders[] = $name . ':quota_exhausted';
                    $this->logRepo->log($name, 'quota_exhausted', 0, 'QUOTA_EXHAUSTED', 'screenshot_extraction', $entryModel, $routeOverride, $fallbackIndex);
                    $lastException = new AIQuotaExhaustedException('Quota exhausted for ' . $name, $name);
                    continue;
                }
                $triedProviders[] = $name . ':quota_race_exhausted';
                $this->logRepo->log($name, 'quota_exhausted', 0, 'QUOTA_RACE', 'screenshot_extraction', $entryModel, $routeOverride, $fallbackIndex);
                $lastException = new AIQuotaExhaustedException('Quota race exhausted for ' . $name, $name);
                continue;
            }

            // Privacy: anonymize for external providers — FAIL-CLOSED.
            // Never send the original image to an external provider if
            // anonymization fails; skip to the local fallback instead.
            $imageToSend = $imageRaw;
            if ($name !== 'tesseract' && ImageAnonymizer::shouldAnonymize($imageRaw)) {
                $anonymized = ImageAnonymizer::anonymize($imageRaw);
                if ($anonymized === null || $anonymized === '') {
                    $triedProviders[] = $name . ':anonymization_failed';
                    $this->logRepo->log($name, 'failed', 0, 'ANONYMIZATION_FAILED', 'screenshot_extraction', $entryModel, $routeOverride, $fallbackIndex);
                    $lastException = new AIProviderException(
                        'Image anonymization failed; refusing to send the original image to an external provider.',
                        $name,
                    );
                    continue;
                }
                $imageToSend = $anonymized;
            }

            $start = microtime(true);
            try {
                // Explicit per-feature route override is only meaningful (and
                // validated) for Gemini; other providers always use their
                // default transport.
                if ($routeOverride !== null && $provider instanceof GeminiProvider) {
                    $result = $provider->extract($imageToSend, $deadline, $routeOverride);
                } else {
                    $result = $provider->extract($imageToSend, $deadline);
                }
                $latency = (int) ((microtime(true) - $start) * 1000);

                if ($result->confidence < 0.2 && $name !== 'tesseract') {
                    $triedProviders[] = $name . ':low_confidence';
                    $this->logRepo->log($name, 'failed', $latency, 'LOW_CONFIDENCE', 'screenshot_extraction', $entryModel, $routeOverride, $fallbackIndex);
                    $lastException = new AIValidationException('Low confidence from ' . $name, ['provider' => ['code' => 'LOW_CONFIDENCE']]);
                    continue;
                }

                $this->logRepo->log($name, 'success', $latency, null, 'screenshot_extraction', $entryModel ?? $result->provider, $routeOverride, $fallbackIndex);
                return $result;
            } catch (AIQuotaExhaustedException $e) {
                $latency = (int) ((microtime(true) - $start) * 1000);
                $triedProviders[] = $name . ':quota_exhausted';
                $this->logRepo->log($name, 'quota_exhausted', $latency, $e->errorCode(), 'screenshot_extraction', $entryModel, $routeOverride, $fallbackIndex);
                $lastException = $e;
                continue;
            } catch (AITimeoutException $e) {
                $latency = (int) ((microtime(true) - $start) * 1000);
                $triedProviders[] = $name . ':timeout';
                $this->logRepo->log($name, 'timeout', $latency, $e->errorCode(), 'screenshot_extraction', $entryModel, $routeOverride, $fallbackIndex);
                $lastException = $e;
                continue;
            } catch (AIValidationException $e) {
                $latency = (int) ((microtime(true) - $start) * 1000);
                if (AIFailureClassifier::classify($e) === AIFailureClassifier::ABORT) {
                    // Invalid input/extraction contract — never fall through.
                    $this->logRepo->log($name, 'failed', $latency, $e->errorCode(), 'screenshot_extraction', $entryModel, $routeOverride, $fallbackIndex);
                    throw $e;
                }
                // Provider-output quality (malformed/low confidence) — fallback.
                $triedProviders[] = $name . ':validation_failed';
                $this->logRepo->log($name, 'failed', $latency, $e->errorCode(), 'screenshot_extraction', $entryModel, $routeOverride, $fallbackIndex);
                $lastException = $e;
                continue;
            } catch (AIProviderException $e) {
                $latency = (int) ((microtime(true) - $start) * 1000);
                if (AIFailureClassifier::classify($e) === AIFailureClassifier::ABORT) {
                    $this->logRepo->log($name, 'failed', $latency, $e->errorCode(), 'screenshot_extraction', $entryModel, $routeOverride, $fallbackIndex);
                    throw $e;
                }
                $triedProviders[] = $name . ':provider_error';
                $this->logRepo->log($name, 'failed', $latency, $e->errorCode(), 'screenshot_extraction', $entryModel, $routeOverride, $fallbackIndex);
                $lastException = $e;
                continue;
            } catch (AIException $e) {
                $latency = (int) ((microtime(true) - $start) * 1000);
                if (AIFailureClassifier::classify($e) === AIFailureClassifier::ABORT) {
                    $this->logRepo->log($name, 'failed', $latency, $e->errorCode(), 'screenshot_extraction', $entryModel, $routeOverride, $fallbackIndex);
                    throw $e;
                }
                $triedProviders[] = $name . ':ai_error';
                $this->logRepo->log($name, 'failed', $latency, $e->errorCode(), 'screenshot_extraction', $entryModel, $routeOverride, $fallbackIndex);
                $lastException = $e;
                continue;
            }
        }

        if ($lastException !== null) {
            throw $lastException;
        }

        throw new AIProviderException(
            'All AI providers failed. Tried: ' . implode(', ', $triedProviders),
            'manager',
            ['tried' => $triedProviders],
        );
    }

    public function getProvider(string $name): ?AIProviderInterface
    {
        foreach ($this->providers as $p) {
            if ($p->getName() === $name) {
                return $p;
            }
        }
        return null;
    }

    public function getProviderNames(): array
    {
        return array_map(fn(AIProviderInterface $p) => $p->getName(), $this->providers);
    }

    /**
     * Check if user has given AI consent (ai_consent_at not null).
     * Fail open if column/table missing (backward compat until v0.6 applied).
     */
    /**
     * Check if user has given AI consent (ai_consent_at not null).
     * After v0.6 exists: fail-closed for external providers if column missing.
     * Tesseract/local may remain available.
     */
    private function hasAIConsent(int $userId): bool
    {
        try {
            $pdo = Database::connection();
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $stmt = $pdo->prepare(
                    'SELECT ai_consent_at FROM users WHERE id = :id LIMIT 1'
                );
                $stmt->execute(['id' => $userId]);
                $row = $stmt->fetch();
                if ($row === false) {
                    return false;
                }
                return $row['ai_consent_at'] !== null && $row['ai_consent_at'] !== '';
            }

            // SQLite fallback for tests
            $stmt = $pdo->prepare('SELECT ai_consent_at FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch();
            if ($row === false) {
                return false;
            }
            return isset($row['ai_consent_at']) && $row['ai_consent_at'] !== null && $row['ai_consent_at'] !== '';
        } catch (\Throwable $e) {
            // After v0.6: if consent column missing, external AI must reject (fail-closed)
            if (stripos($e->getMessage(), 'ai_consent_at') !== false || stripos($e->getMessage(), 'Unknown column') !== false) {
                error_log('[VELORA_AI_CONSENT] column missing, fail-closed for external AI');
                return false;
            }
            // Other DB errors — fail-closed for external AI to avoid privacy violation
            error_log('[VELORA_AI_CONSENT] check failed, fail-closed: ' . $e->getMessage());
            return false;
        }
    }
}
