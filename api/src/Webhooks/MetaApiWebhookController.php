<?php

declare(strict_types=1);

namespace Velora\Webhooks;

use Velora\Accounts\AccountRepository;
use Velora\Accounts\MetaApiService;
use Velora\Accounts\WebhookEventRepository;
use Velora\Core\Config;
use Velora\Core\Exceptions\ApiException;
use Velora\Core\Request;
use Velora\Core\Response;

/** Authenticated, freshness-bounded, replay-safe MetaApi webhook ingress. */
final class MetaApiWebhookController
{
    private const MAX_BODY_BYTES = 1_048_576;

    public function __construct(
        private readonly MetaApiService $service = new MetaApiService(),
        private readonly WebhookEventRepository $events = new WebhookEventRepository(),
        private readonly AccountRepository $accounts = new AccountRepository(),
    ) {
    }

    public function handle(Request $request): never
    {
        Response::json($this->ingest($request));
    }

    private function ingest(Request $request): array
    {
        if ($request->rawBody === '' || strlen($request->rawBody) > self::MAX_BODY_BYTES) {
            throw new ApiException('Webhook payload is missing or too large.', 413, 'PAYLOAD_TOO_LARGE');
        }
        $secret = (string) Config::env('METAAPI_WEBHOOK_SECRET', '');
        if ($secret === '') {
            throw new ApiException('Webhook authentication is not configured.', 503, 'WEBHOOK_SECRET_MISSING');
        }

        // Preserve the existing raw-body HMAC contract.
        $rawSignature = $request->headers['x-metaapi-signature']
            ?? $request->headers['x-webhook-signature']
            ?? $request->headers['authorization']
            ?? null;
        $rawSignature = $this->normalizeSignature(is_string($rawSignature) ? $rawSignature : null);
        $expectedRaw = hash_hmac('sha256', $request->rawBody, $secret);
        if ($rawSignature === null || !hash_equals($expectedRaw, $rawSignature)) {
            throw new ApiException('HMAC verification failed.', 401, 'HMAC_FAILED');
        }

        $payload = $request->body;
        if ($payload === []) {
            $decoded = json_decode($request->rawBody, true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        if ($payload === []) {
            throw new ApiException('Invalid webhook JSON.', 422, 'INVALID_WEBHOOK_PAYLOAD');
        }

        // A timestamp header is separately bound to the raw body, or a
        // dedicated timestamp inside the already raw-body-signed JSON may be
        // used. Generic trade/deal timestamps are intentionally not treated as
        // delivery timestamps.
        $headerTimestamp = $request->headers['x-metaapi-timestamp'] ?? null;
        $timestampSignature = $request->headers['x-metaapi-timestamp-signature'] ?? null;
        if (is_string($headerTimestamp) && $headerTimestamp !== '') {
            $timestampSignature = $this->normalizeSignature(
                is_string($timestampSignature) ? $timestampSignature : null
            );
            $expectedTimestampSignature = hash_hmac(
                'sha256',
                $headerTimestamp . '.' . $request->rawBody,
                $secret,
            );
            if ($timestampSignature === null || !hash_equals($expectedTimestampSignature, $timestampSignature)) {
                throw new ApiException('Webhook timestamp signature failed.', 401, 'WEBHOOK_TIMESTAMP_HMAC_FAILED');
            }
            $eventTimestamp = $this->parseTimestamp($headerTimestamp);
        } else {
            $eventTimestamp = $this->parseTimestamp($payload['webhookTimestamp'] ?? null);
        }
        $maxAge = max(30, (int) Config::get('metaapi.webhook_max_age_seconds', 300));
        $now = time();
        if ($eventTimestamp === null || $eventTimestamp < $now - $maxAge || $eventTimestamp > $now + 30) {
            throw new ApiException('Webhook timestamp is stale or invalid.', 401, 'WEBHOOK_TIMESTAMP_INVALID');
        }

        $metaapiId = $this->normalizeIdentifier($payload['accountId'] ?? $payload['metaapi_account_id'] ?? null);
        $rawType = $payload['type'] ?? $payload['event'] ?? null;
        $eventType = is_string($rawType) ? strtolower(trim($rawType)) : '';
        if ($metaapiId === null || $eventType === '' || strlen($eventType) > 50
            || preg_match('/\A[a-z0-9._:-]+\z/D', $eventType) !== 1) {
            throw new ApiException('Invalid webhook identifiers.', 422, 'INVALID_WEBHOOK_IDENTIFIERS');
        }

        $explicitEventId = $this->normalizeIdentifier($payload['eventId'] ?? $payload['id'] ?? null);
        $replayMaterial = $explicitEventId ?? hash('sha256', $request->rawBody);
        $eventKey = hash('sha256', $metaapiId . "\0" . $eventType . "\0" . $replayMaterial);
        $account = $this->accounts->findByMetaApiId($metaapiId);
        $claim = $this->events->claim(
            $eventKey,
            $account === null ? null : (int) $account['id'],
            $metaapiId,
            $eventType,
            $payload,
        );

        if ($claim['claim_token'] === null) {
            return [
                'ok' => true,
                'verified' => true,
                'duplicate' => true,
                'processed' => $claim['processed'],
                'eventId' => $claim['id'],
            ];
        }

        try {
            $result = $this->service->processWebhook($payload);
            if (!$this->events->markProcessed((int) $claim['id'], (string) $claim['claim_token'])) {
                throw new ApiException('Webhook processing lease was lost.', 503, 'WEBHOOK_LEASE_LOST');
            }
        } catch (\Throwable $e) {
            $this->events->releaseAfterFailure(
                (int) $claim['id'],
                (string) $claim['claim_token'],
                $e instanceof ApiException ? (string) $e->errorCode() : 'WEBHOOK_PROCESSING_FAILED',
            );
            throw $e;
        }

        return [
            'ok' => true,
            'verified' => true,
            'duplicate' => $claim['duplicate'],
            'processed' => true,
            'eventId' => $claim['id'],
            'result' => $result,
        ];
    }

    /** Development-only signed fixture using the same production ingress path. */
    public function test(Request $request): never
    {
        if (!Config::isDevelopmentEnvironment()) {
            Response::error('Not found.', 404, 'NOT_FOUND');
        }
        $secret = (string) Config::env('METAAPI_WEBHOOK_SECRET', '');
        if ($secret === '') {
            throw new ApiException('Webhook authentication is not configured.', 503, 'WEBHOOK_SECRET_MISSING');
        }
        $payload = [
            'eventId' => 'dev-' . bin2hex(random_bytes(8)),
            'accountId' => 'mock_' . bin2hex(random_bytes(4)),
            'type' => 'health',
            'webhookTimestamp' => time(),
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $fixture = new Request('POST', '/api/v1/webhooks/metaapi', [], $payload, [
            'x-metaapi-signature' => hash_hmac('sha256', $raw, $secret),
        ], $raw);
        Response::json(['test' => true, 'result' => $this->ingest($fixture)]);
    }

    private function normalizeSignature(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (str_starts_with(strtolower($value), 'sha256=')) {
            $value = substr($value, 7);
        } elseif (str_starts_with(strtolower($value), 'bearer ')) {
            $value = trim(substr($value, 7));
        }
        return preg_match('/\A[a-f0-9]{64}\z/Di', $value) === 1 ? strtolower($value) : null;
    }

    private function parseTimestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            $timestamp = $value;
        } elseif (is_string($value) && preg_match('/\A\d{10,13}\z/D', trim($value)) === 1) {
            $timestamp = (int) trim($value);
        } elseif (is_string($value) && strlen($value) <= 64) {
            $parsed = strtotime($value);
            return $parsed === false ? null : $parsed;
        } else {
            return null;
        }
        return $timestamp > 9_999_999_999 ? intdiv($timestamp, 1000) : $timestamp;
    }

    private function normalizeIdentifier(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' && strlen($value) <= 64
            && preg_match('/\A[A-Za-z0-9._:-]+\z/D', $value) === 1
            ? $value
            : null;
    }
}
