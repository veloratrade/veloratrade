<?php

declare(strict_types=1);

namespace Velora\Core;

/**
 * Standardized JSON REST API contract (roadmap v0.1):
 *   { "status": "success"|"error", "data": ..., "error": {...}|null, "timestamp": ISO8601 }
 */
final class Response
{
    public const REFRESH_COOKIE_NAME = '__Host-velora_refresh';

    /** Refresh credentials are emitted only through this host-bound cookie. */
    public static function setRefreshCookie(string $token, int $ttlSeconds): void
    {
        if ($token === '' || strlen($token) > 128) {
            throw new \InvalidArgumentException('Invalid refresh credential.');
        }
        setcookie(self::REFRESH_COOKIE_NAME, $token, [
            'expires' => time() + max(1, $ttlSeconds),
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public static function clearRefreshCookie(): void
    {
        setcookie(self::REFRESH_COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        self::applyCors();
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        $payload = [
            'status' => $status >= 200 && $status < 300 ? 'success' : 'error',
            'data' => $data,
            'error' => null,
            'timestamp' => gmdate('c'),
        ];

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function corsPreflight(): never
    {
        http_response_code(204);
        self::applyCors();
        header('Cache-Control: no-store');
        exit;
    }

    public static function error(
        string $message,
        int $status = 400,
        ?string $code = null,
        mixed $details = null,
        ?string $messageKey = null,
        array $params = [],
    ): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        self::applyCors();
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        $payload = [
            'status' => 'error',
            'data' => null,
            'error' => [
                'code' => $code ?? self::defaultCode($status),
                // UI clients render messageKey through their own locale catalog. `message`
                // is a stable, language-neutral compatibility fallback.
                'message' => self::defaultMessage($status),
                'messageKey' => $messageKey ?? self::defaultMessageKey($status),
                'params' => (object) $params,
                'details' => $details,
            ],
            'timestamp' => gmdate('c'),
        ];

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function defaultCode(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            413 => 'PAYLOAD_TOO_LARGE',
            422 => 'VALIDATION_FAILED',
            429 => 'TOO_MANY_REQUESTS',
            default => 'INTERNAL_ERROR',
        };
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Invalid request.',
            401 => 'Authentication required.',
            403 => 'Access denied.',
            404 => 'Resource not found.',
            405 => 'Method not allowed.',
            409 => 'Request conflict.',
            413 => 'Request body is too large.',
            422 => 'Validation failed.',
            429 => 'Too many requests.',
            502 => 'Upstream service unavailable.',
            503 => 'Service unavailable.',
            504 => 'Upstream service timeout.',
            default => 'Internal server error.',
        };
    }

    private static function defaultMessageKey(int $status): string
    {
        return match ($status) {
            401 => 'errors.unauthorized',
            403 => 'errors.forbidden',
            404 => 'errors.notFound',
            409 => 'errors.conflict',
            422 => 'errors.validation',
            429 => 'errors.rateLimited',
            400, 405, 413, 500, 502, 503, 504 => 'errors.http.' . $status,
            default => 'errors.unknown',
        };
    }

    private static function applyCors(): void
    {
        $allowed = Config::get('cors_allowed_origins', ['*']);
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array('*', $allowed, true)) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '' && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-MetaApi-Signature, X-Webhook-Signature');
        header('Access-Control-Max-Age: 86400');
    }
}
