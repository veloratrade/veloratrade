<?php

declare(strict_types=1);

namespace Velora\AI\Providers;

/**
 * Provider verification abstraction.
 *
 * Two related but distinct concepts are exposed:
 *   - verifyCredential():  does this credential AUTHENTICATE with the provider?
 *   - testConnection():    can Velora REACH and communicate with the integration?
 *
 * They are deliberately separate because RELAY_REACHABLE does not imply
 * GEMINI_CREDENTIAL_VALID, and CONNECTED does not imply VALID.
 *
 * Capability discovery is explicit via capabilities(): a false value means the
 * operation is NOT implemented and MUST be surfaced as unavailable, never
 * fabricated.
 */
interface ProviderVerifierInterface
{
    public function provider(): string;

    /** @return array<string,bool> e.g. ['validate_credentials'=>true,'connection_test'=>true,'list_models'=>false] */
    public function capabilities(): array;

    /** Authoritative check that the configured credential authenticates. */
    public function verifyCredential(): VerificationResult;

    /** Reachability connectivity check for the configured integration. */
    public function testConnection(): VerificationResult;
}
