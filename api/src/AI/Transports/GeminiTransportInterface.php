<?php

declare(strict_types=1);

namespace Velora\AI\Transports;

/**
 * Single contract for Gemini vision/text transports.
 *
 * Velora must be able to switch HOW it talks to Gemini (direct HTTPS vs an
 * n8n Cloud relay while the API host sits in an unsupported region) without
 * touching business logic: the provider picks a transport, everything above
 * the provider is unaware of the route.
 *
 * Implementations MUST:
 * - read secrets only from environment (Config::env), never hardcode them
 * - never include secrets in exception messages
 * - map failures onto Velora\AI\Exceptions\AIException subclasses
 */
interface GeminiTransportInterface
{
    /** Transport identifier, e.g. 'direct' or 'n8n_relay'. */
    public function getName(): string;

    /** Whether the transport has everything it needs (URL/token/API key). */
    public function isConfigured(): bool;

    /**
     * Run one generateContent round-trip.
     *
     * @param string $prompt Prompt text (PromptManager output, possibly with data envelope)
     * @param string|null $imageRaw Raw image bytes or null for text-only
     * @param string|null $mime Detected image MIME type when $imageRaw is set
     * @param array<string,mixed> $options model, temperature, maxOutputTokens, responseMimeType
     * @param int $timeout Whole-call timeout in seconds
     * @return array{text:?string, model:string, http_code:int, latency_ms:int, raw:array<string,mixed>}
     *
     * @throws \Velora\AI\Exceptions\AIException mapped failure
     */
    public function generateContent(
        string $prompt,
        ?string $imageRaw,
        ?string $mime,
        array $options,
        int $timeout
    ): array;
}
