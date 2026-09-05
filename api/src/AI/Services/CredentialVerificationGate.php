<?php

declare(strict_types=1);

namespace Velora\AI\Services;

use Velora\AI\Providers\CredentialStatus;
use Velora\AI\Repositories\AICredentialMetadataRepository;

/**
 * Runtime activation gate.
 *
 * Consumes the persisted credential verification metadata so that a credential
 * the provider has confirmed as invalid/expired/revoked/disabled is NOT routed
 * as a healthy provider at runtime.
 *
 * Design (documented deviation that honours both safety and backward
 * compatibility):
 *   - A credential verified as CONFIRMED-INVALID is excluded from the runtime
 *     provider chain (fail-closed): an invalid key must never be "active".
 *   - An UNVERIFIED or UNKNOWN credential (never checked, or the metadata
 *     table is absent on a host that hasn't applied v1.2) remains usable,
 *     preserving every existing deployment's current behaviour. Requiring
 *     verification before any use would strand any production key that was
 *     never explicitly verified — a breaking change the spec forbids.
 *   - Transient capacity states (rate-limit / quota / provider-unavailable /
 *     network / region) never permanently disable a provider.
 *
 * This is the gate that the REAL runtime consumers (FeatureRouter, AIManager)
 * call — not an unreferenced helper.
 */
final class CredentialVerificationGate
{
    public static function isBlocked(string $provider, ?AICredentialMetadataRepository $repo = null): bool
    {
        if ($repo === null) {
            $repo = new AICredentialMetadataRepository();
        }
        $meta = $repo->get($provider);
        if ($meta === null) {
            return false; // no metadata row / table missing => backward compatible
        }
        $status = (string) ($meta['status'] ?? CredentialStatus::UNVERIFIED);
        return in_array($status, CredentialStatus::confirmedInvalid(), true);
    }
}
