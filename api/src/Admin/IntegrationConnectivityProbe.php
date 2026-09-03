<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\IntegrationConfigResolver;

/**
 * Phase C — Safe, bounded connectivity/authentication probes for Admin-managed
 * integrations (MetaAPI, Email).
 *
 * Purpose: let an Administrator distinguish "Configured" from "Reachable" /
 * "Authenticated" without us ever claiming connectivity we did not actually
 * test. Every probe is:
 *   - server-side (never a browser call),
 *   - bounded (short connect+total timeout),
 *   - secret-free (a credential value/header is never returned, logged, or
 *     placed in an exception),
 *   - classified to a small, explicit taxonomy.
 *
 * The HTTP layer is injectable for deterministic unit tests (no real external
 * call); the default is a curl-based client. This mirrors the Phase A
 * GeminiCredentialVerifier contract:
 *
 *   ($method, $url, $headers) => ['status'=>int, 'body'=>string, 'curlErrno'=>int]
 *
 * Classification (never fabricated): NOT_CONFIGURED, SUCCESS, AUTH_FAILED,
 * TIMEOUT, NETWORK_ERROR, SERVICE_UNAVAILABLE.
 *
 * IMPORTANT (email): a "test" NEVER sends a real email to a user. It performs a
 * connectivity/auth handshake only (Resend: an authenticated list-domains GET
 * with the key, no message; SMTP: TCP+STARTTLS+AUTH with no RCPT/DATA).
 */
final class IntegrationConnectivityProbe
{
    public const RESULT_NOT_CONFIGURED = 'NOT_CONFIGURED';
    public const RESULT_SUCCESS = 'SUCCESS';
    public const RESULT_AUTH_FAILED = 'AUTH_FAILED';
    public const RESULT_TIMEOUT = 'TIMEOUT';
    public const RESULT_NETWORK_ERROR = 'NETWORK_ERROR';
    public const RESULT_SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    private const DEFAULT_TIMEOUT = 8;
    private const RESEND_ENDPOINT = 'https://api.resend.com/domains';

    /** @var callable|null */
    private $http;

    public function __construct(?callable $http = null)
    {
        $this->http = $http;
    }

    /** MetaAPI: prove the platform token authenticates against the API. */
    public function metaApi(): array
    {
        $token = IntegrationConfigResolver::metaApiToken();
        if ($token === '') {
            return self::result(self::RESULT_NOT_CONFIGURED, 'MetaAPI token is not configured.');
        }
        $base = rtrim(IntegrationConfigResolver::metaApiBaseUrl(), '/');
        $url = $base . '/users/current';

        try {
            $resp = $this->call('GET', $url, ['auth-token' => $token], self::DEFAULT_TIMEOUT);
        } catch (\Throwable $e) {
            return self::result(self::RESULT_NETWORK_ERROR, null);
        }

        $status = (int) ($resp['status'] ?? 0);
        $latency = (int) ($resp['latency_ms'] ?? 0);

        if ($status >= 200 && $status < 300) {
            return self::result(self::RESULT_SUCCESS, null, $latency);
        }
        if ($status === 401 || $status === 403) {
            return self::result(self::RESULT_AUTH_FAILED, null, $latency);
        }
        if ($status === 408 || $status === 425 || $status >= 500) {
            return self::result($status >= 500 ? self::RESULT_SERVICE_UNAVAILABLE : self::RESULT_TIMEOUT, null, $latency);
        }
        // 429 / other client errors: treated as service reachable but rejected.
        return self::result(self::RESULT_SERVICE_UNAVAILABLE, null, $latency);
    }

    /**
     * Email: verify the configured provider's credential WITHOUT sending mail.
     * resend: authenticated GET /domains (no message). smtp: TCP+STARTTLS+AUTH.
     * log/mail(): nothing external to verify -> config-only SUCCESS.
     */
    public function email(): array
    {
        $driver = IntegrationConfigResolver::mailDriver();
        if ($driver === 'log' || $driver === 'mail') {
            // No external credential to validate; only report configuration state.
            return self::result(self::RESULT_SUCCESS, null);
        }
        if ($driver === 'resend') {
            $key = IntegrationConfigResolver::mailResendApiKey();
            if ($key === '') {
                return self::result(self::RESULT_NOT_CONFIGURED, 'Resend API key is not configured.');
            }
            try {
                $resp = $this->call('GET', self::RESEND_ENDPOINT, ['Authorization' => 'Bearer ' . $key], self::DEFAULT_TIMEOUT);
            } catch (\Throwable $e) {
                return self::result(self::RESULT_NETWORK_ERROR, null);
            }
            $status = (int) ($resp['status'] ?? 0);
            $latency = (int) ($resp['latency_ms'] ?? 0);
            if ($status >= 200 && $status < 300) {
                return self::result(self::RESULT_SUCCESS, null, $latency);
            }
            if ($status === 401 || $status === 403) {
                return self::result(self::RESULT_AUTH_FAILED, null, $latency);
            }
            if ($status >= 500) {
                return self::result(self::RESULT_SERVICE_UNAVAILABLE, null, $latency);
            }
            if ($status === 408 || $status === 425) {
                return self::result(self::RESULT_TIMEOUT, null, $latency);
            }
            return self::result(self::RESULT_SERVICE_UNAVAILABLE, null, $latency);
        }
        if ($driver === 'smtp') {
            return $this->smtpProbe();
        }
        return self::result(self::RESULT_NOT_CONFIGURED, 'Unknown email driver.');
    }

    /** SMTP: connect + STARTTLS + AUTH only (never RCPT/DATA). No mail is sent. */
    private function smtpProbe(): array
    {
        $host = IntegrationConfigResolver::mailSmtpHost();
        $port = IntegrationConfigResolver::mailSmtpPort();
        $user = IntegrationConfigResolver::mailSmtpUser();
        $pass = IntegrationConfigResolver::mailSmtpPassword();
        if ($host === '' || $user === '' || $pass === '') {
            return self::result(self::RESULT_NOT_CONFIGURED, 'SMTP is not fully configured.');
        }
        $socketHost = $port === 465 ? 'ssl://' . $host : $host;
        $timeout = min(6, self::DEFAULT_TIMEOUT);
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'SNI_enabled' => true], 'socket' => ['timeout' => $timeout]]);
        $sock = @stream_socket_client($socketHost . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$sock) {
            return self::result(self::RESULT_NETWORK_ERROR, null);
        }
        stream_set_timeout($sock, $timeout);
        $start = microtime(true);

        if (!str_starts_with(self::smtpRead($sock), '220')) {
            fclose($sock);
            return self::result(self::RESULT_SERVICE_UNAVAILABLE, null);
        }
        $ehlo = 'localhost';
        if (!str_starts_with(self::smtpCmd($sock, 'EHLO ' . $ehlo), '250')) {
            fclose($sock);
            return self::result(self::RESULT_SERVICE_UNAVAILABLE, null);
        }
        if ($port !== 465) {
            if (!str_starts_with(self::smtpCmd($sock, 'STARTTLS'), '220')
                || !stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
                || !str_starts_with(self::smtpCmd($sock, 'EHLO ' . $ehlo), '250')) {
                fclose($sock);
                return self::result(self::RESULT_SERVICE_UNAVAILABLE, null);
            }
        }
        $auth = str_starts_with(self::smtpCmd($sock, 'AUTH LOGIN'), '334')
            && str_starts_with(self::smtpCmd($sock, base64_encode($user)), '334')
            && str_starts_with(self::smtpCmd($sock, base64_encode($pass)), '235');
        self::smtpCmd($sock, 'QUIT');
        fclose($sock);
        $latency = (int) round((microtime(true) - $start) * 1000);

        if (!$auth) {
            return self::result(self::RESULT_AUTH_FAILED, null, $latency);
        }
        return self::result(self::RESULT_SUCCESS, null, $latency);
    }

    private static function smtpRead($sock): string
    {
        $last = '';
        do {
            $line = fgets($sock, 515);
            if ($line === false) {
                break;
            }
            $last = trim($line);
        } while (strlen($line) >= 4 && $line[3] === '-');
        return $last;
    }

    private static function smtpCmd($sock, string $command): string
    {
        fwrite($sock, $command . "\r\n");
        return self::smtpRead($sock);
    }

    /** @return array{status:int,latency_ms:int} */
    private function call(string $method, string $url, array $headers, int $timeout): array
    {
        $start = microtime(true);
        if ($this->http !== null) {
            $result = ($this->http)($method, $url, $headers, $timeout);
            $latency = (int) round((microtime(true) - $start) * 1000);
            return ['status' => (int) ($result['status'] ?? 0), 'latency_ms' => $latency];
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL is unavailable.');
        }
        $ch = curl_init($url);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        $latency = (int) round((microtime(true) - $start) * 1000);
        if ($raw === false || $curlErrno !== 0 || $status === 0) {
            // CURLE_OPERATION_TIMEDOUT is 28.
            $kind = $curlErrno === 28 ? self::RESULT_TIMEOUT : self::RESULT_NETWORK_ERROR;
            throw new \RuntimeException($kind);
        }
        return ['status' => $status, 'latency_ms' => $latency];
    }

    /** @return array<string,mixed> */
    private static function result(string $status, ?string $message, int $latencyMs = 0): array
    {
        return [
            'status' => $status,
            'reachable' => $status === self::RESULT_SUCCESS,
            'verified' => $status === self::RESULT_SUCCESS,
            'latencyMs' => $latencyMs,
            'checkedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'message' => $message,
        ];
    }
}
