<?php

declare(strict_types=1);

namespace Velora\AI\Services;

use Velora\AI\Providers\GeminiCredentialVerifier;
use Velora\AI\Providers\ProviderVerifierInterface;
use Velora\AI\Providers\VerificationResult;

/**
 * Maps a registered provider to its verification implementation.
 *
 * Only providers with a concrete verifier are listed; `verifierFor()` returns
 * null for providers whose verification is not yet implemented (openai, claude,
 * tesseract) so the admin API can honestly report CAPABILITY_UNAVAILABLE /
 * UNKNOWN instead of fabricating a result.
 *
 * The registry is injectable so admin controllers are unit-testable without
 * hitting the network (tests supply a fake verifier).
 */
final class ProviderVerifierRegistry
{
    /** @var array<string,class-string<ProviderVerifierInterface>> */
    private const VERIFIERS = [
        'gemini' => GeminiCredentialVerifier::class,
    ];

    /** @var array<string,ProviderVerifierInterface> */
    private array $instances = [];

    public function __construct(?array $injected = null)
    {
        if ($injected !== null) {
            foreach ($injected as $instance) {
                if ($instance instanceof ProviderVerifierInterface) {
                    $this->instances[$instance->provider()] = $instance;
                }
            }
        }
    }

    public function hasVerifier(string $provider): bool
    {
        return $this->verifierFor($provider) !== null;
    }

    public function verifierFor(string $provider): ?ProviderVerifierInterface
    {
        $provider = strtolower(trim($provider));
        if (isset($this->instances[$provider])) {
            return $this->instances[$provider];
        }
        $class = self::VERIFIERS[$provider] ?? null;
        if ($class === null || !class_exists($class)) {
            return null;
        }
        try {
            $instance = new $class();
            return $this->instances[$provider] = ($instance instanceof ProviderVerifierInterface) ? $instance : null;
        } catch (\Throwable $e) {
            // Verification must never leak internals; absent verifier = unavailable.
            return null;
        }
    }

    /** @return string[] */
    public function supportedProviders(): array
    {
        return array_keys(self::VERIFIERS);
    }

    /** Honest unsupported/none result for a provider without a verifier. */
    public function unsupportedFor(string $provider, string $capability): VerificationResult
    {
        return VerificationResult::unsupported(strtolower(trim($provider)), $capability);
    }
}
