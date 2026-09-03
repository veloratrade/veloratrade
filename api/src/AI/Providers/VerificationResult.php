<?php

declare(strict_types=1);

namespace Velora\AI\Providers;

/**
 * Immutable, secret-free provider verification result.
 *
 * `toArray()` is the ONLY safe serialization and is designed to be returned
 * directly by an admin API. It NEVER contains a credential value, an API key,
 * a relay token, an authorization header, a raw upstream error body, or a
 * private filesystem path. Provider error detail is reduced to a safe,
 * allowlisted `code` plus a sanitized generic `message`.
 */
final class VerificationResult
{
    public function __construct(
        private readonly string $provider,
        private readonly string $status,
        private readonly bool $verified,
        private readonly ?bool $reachable,
        private readonly string $checkedAt,
        private readonly int $latencyMs,
        private readonly ?string $code,
        private readonly ?string $message,
        private readonly bool $retryable,
        private readonly string $source, // 'direct' | 'relay' | 'provider' | 'local' | 'unsupported'
    ) {
    }

    public static function unknown(string $provider, string $source, ?string $code = null, ?string $message = null, int $latencyMs = 0, ?bool $reachable = null): self
    {
        return new self($provider, CredentialStatus::UNKNOWN, false, $reachable, gmdate('Y-m-d\TH:i:s\Z'), $latencyMs, $code, $message, false, $source);
    }

    public static function unsupported(string $provider, string $capability): self
    {
        return new self(
            $provider,
            CredentialStatus::UNKNOWN,
            false,
            null,
            gmdate('Y-m-d\TH:i:s\Z'),
            0,
            'CAPABILITY_UNAVAILABLE',
            'The provider does not expose this operation through the available API/verification surface.',
            false,
            'unsupported',
        );
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function verified(): bool
    {
        return $this->verified;
    }

    public function reachable(): ?bool
    {
        return $this->reachable;
    }

    public function latencyMs(): int
    {
        return $this->latencyMs;
    }

    public function code(): ?string
    {
        return $this->code;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function checkedAt(): string
    {
        return $this->checkedAt;
    }

    /** Only VALID is eligible for activation — see CredentialStatus. */
    public function isEligibleForActivation(): bool
    {
        return CredentialStatus::isEligibleForActivation($this->status);
    }

    /**
     * Safe serialization for the API. All fields are metadata-only; no secret
     * can ever reach the client from this object.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'status' => $this->status,
            'verified' => $this->verified,
            'reachable' => $this->reachable,
            'checked_at' => $this->checkedAt,
            'latency_ms' => $this->latencyMs,
            'error_code' => $this->code,
            'message' => $this->message,
            'retryable' => $this->retryable,
            'source' => $this->source,
        ];
    }
}
