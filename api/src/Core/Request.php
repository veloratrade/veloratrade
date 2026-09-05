<?php

declare(strict_types=1);

namespace Velora\Core;

use Velora\Core\Exceptions\ApiException;

/**
 * Immutable HTTP request value object.
 */
final class Request
{
    /** Mutable slot for middleware to pass data to handlers (e.g. resolved user). */
    public array $attributes = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers,
        public readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Parse request path, stripping query string.
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $body = [];

        // Bound request bodies before reading them into memory. CONTENT_LENGTH
        // is only an early rejection hint; the limited stream read is the
        // authoritative check for chunked or dishonest requests.
        $maxBytes = max(1, (int) Config::get('request_max_bytes', 25_165_824));
        $pathLimits = Config::get('request_path_max_bytes', []);
        $pathLimitKey = $method . ' ' . $path;
        if (is_array($pathLimits) && isset($pathLimits[$pathLimitKey])) {
            $maxBytes = min($maxBytes, max(1, (int) $pathLimits[$pathLimitKey]));
        }
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;
        if ($contentLength !== null && $contentLength > $maxBytes) {
            throw new ApiException('Request body is too large.', 413, 'PAYLOAD_TOO_LARGE');
        }
        $input = fopen('php://input', 'rb');
        $raw = is_resource($input) ? stream_get_contents($input, $maxBytes + 1) : '';
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_string($raw) && strlen($raw) > $maxBytes) {
            throw new ApiException('Request body is too large.', 413, 'PAYLOAD_TOO_LARGE');
        }

        // Try to read JSON body from php://input.
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $body = $json;
            }
        }

        // Fallback: if body is empty and content-type is form-encoded, use $_POST
        if ($body === [] && !empty($_POST)) {
            $body = $_POST;
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['HTTP_AUTHORIZATION'];
        }

        return new self($method, $path, $_GET, $body, $headers, is_string($raw) ? $raw : '');
    }

    public function bearerToken(): ?string
    {
        $auth = $this->headers['authorization'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /** Get a body field, or null when missing. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** Lazily-computed request correlation id (stable for this request). */
    public ?string $requestId = null;

    /** Resolve a best-effort client IP for audit metadata (no secrets). */
    public function clientIp(): ?string
    {
        foreach (['x-forwarded-for', 'x-real-ip'] as $name) {
            $v = $this->headers[$name] ?? '';
            if ($v !== '') {
                $parts = explode(',', $v);
                $first = trim($parts[0] ?? '');
                return $first !== '' ? $first : null;
            }
        }
        return null;
    }

    /** A stable per-request correlation/context id (header-supplied or generated). */
    public function contextId(): ?string
    {
        if ($this->requestId !== null) {
            return $this->requestId;
        }
        $supplied = trim((string) ($this->headers['x-request-id'] ?? ''));
        $this->requestId = $supplied !== '' && strlen($supplied) <= 64
            ? $supplied
            : bin2hex(random_bytes(16));
        return $this->requestId;
    }
}
