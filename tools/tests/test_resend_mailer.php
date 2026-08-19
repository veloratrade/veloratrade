<?php

declare(strict_types=1);

namespace Velora\Core;

/** Dependency-free HTTP mock contract test for Mailer::sendResend(). */
final class Config
{
    /** @var array<string,string> */
    public static array $values = [];

    public static function env(string $key, string $default = ''): string
    {
        return self::$values[$key] ?? $default;
    }

    public static function privatePath(string $relativePath): string
    {
        return sys_get_temp_dir() . '/' . $relativePath;
    }
}

final class CurlMock
{
    public static string $url = '';
    /** @var array<int,mixed> */
    public static array $options = [];
    public static string|false $response = '{"id":"mock-message-id"}';
    public static int $status = 200;
    public static string $error = '';

    public static function reset(): void
    {
        self::$url = '';
        self::$options = [];
        self::$response = '{"id":"mock-message-id"}';
        self::$status = 200;
        self::$error = '';
    }
}

function curl_init(?string $url = null): object
{
    CurlMock::$url = (string) $url;
    return (object) ['mock' => true];
}

/** @param array<int,mixed> $options */
function curl_setopt_array(object $handle, array $options): bool
{
    CurlMock::$options = $options;
    return true;
}

function curl_exec(object $handle): string|false
{
    return CurlMock::$response;
}

function curl_error(object $handle): string
{
    return CurlMock::$error;
}

function curl_getinfo(object $handle, ?int $option = null): mixed
{
    return $option === CURLINFO_RESPONSE_CODE ? CurlMock::$status : [];
}

function curl_close(object $handle): void
{
}

require dirname(__DIR__, 2) . '/api/src/Core/Mailer.php';

$assertions = 0;
function expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$fakeKey = 're_mock_key_must_never_appear_in_errors';
Config::$values = [
    'MAIL_DRIVER' => 'resend',
    'RESEND_API_KEY' => $fakeKey,
];

// Success contract: fixed endpoint/sender, HTML + plain text, and provider id.
CurlMock::reset();
$ok = Mailer::send('recipient@example.test', 'موضوع تست', '<html><body><p>سلام</p></body></html>');
expect($ok, 'Resend success response must return true');
expect(CurlMock::$url === 'https://api.resend.com/emails', 'endpoint must be fixed to the Resend HTTPS API');
expect(Mailer::$lastError === null, 'success must not retain an error');
expect(Mailer::$lastMessageId === 'mock-message-id', 'safe provider message id must be retained');

$payload = json_decode((string) CurlMock::$options[CURLOPT_POSTFIELDS], true, 512, JSON_THROW_ON_ERROR);
expect($payload['from'] === 'VELORA TRADE <no-reply@veloratrade.ir>', 'sender identity must be fixed');
expect($payload['to'] === ['recipient@example.test'], 'recipient must be encoded as a list');
expect($payload['subject'] === 'موضوع تست', 'UTF-8 subject must survive JSON encoding');
expect(str_contains($payload['html'], '<p>سلام</p>'), 'HTML body must be sent');
expect(trim($payload['text']) === 'سلام', 'plain-text alternative must be generated');
expect($payload['reply_to'] === 'no-reply@veloratrade.ir', 'reply-to must stay on the verified domain');

$headers = CurlMock::$options[CURLOPT_HTTPHEADER];
expect(in_array('Authorization: Bearer ' . $fakeKey, $headers, true), 'API key must only be supplied as a Bearer header');
expect(in_array('Content-Type: application/json', $headers, true), 'request must use JSON');
expect(CurlMock::$options[CURLOPT_SSL_VERIFYPEER] === true, 'TLS peer verification must stay enabled');
expect(CurlMock::$options[CURLOPT_SSL_VERIFYHOST] === 2, 'TLS hostname verification must stay enabled');
expect(CurlMock::$options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'only HTTPS must be allowed');

// CID attachment contract used by dedicated transactional icons.
CurlMock::reset();
CurlMock::$response = '{"id":"mock-inline-message-id"}';
$icon = tempnam(sys_get_temp_dir(), 'velora-icon-');
file_put_contents($icon, "PNG-MOCK-BYTES");
$ok = Mailer::sendWithInlineImages(
    'recipient@example.test',
    'Inline icon',
    '<p><img src="cid:velora-verification" alt="Verify"></p>',
    ['velora-verification' => $icon],
    'Verify',
);
expect($ok, 'Resend inline image request must succeed');
$inlinePayload = json_decode((string) CurlMock::$options[CURLOPT_POSTFIELDS], true, 512, JSON_THROW_ON_ERROR);
expect(count($inlinePayload['attachments'] ?? []) === 1, 'one CID attachment must be sent');
expect($inlinePayload['attachments'][0]['content_id'] === 'velora-verification', 'CID content_id mismatch');
expect($inlinePayload['attachments'][0]['filename'] === basename($icon), 'CID filename mismatch');
expect(base64_decode($inlinePayload['attachments'][0]['content'], true) === 'PNG-MOCK-BYTES', 'CID content must be base64 encoded');
expect(Mailer::$lastMessageId === 'mock-inline-message-id', 'inline send message id missing');
unlink($icon);

// Provider rejection: status/message are useful, but key-shaped values are redacted.
CurlMock::reset();
CurlMock::$status = 422;
CurlMock::$response = json_encode([
    'name' => 'validation_error',
    'message' => 'bad request ' . $fakeKey . ' and re_another_key',
], JSON_THROW_ON_ERROR);
$ok = Mailer::send('recipient@example.test', 'Rejected', '<p>Rejected</p>');
expect(!$ok, 'provider rejection must return false');
expect(str_contains((string) Mailer::$lastError, 'Resend API HTTP 422'), 'safe HTTP status must be retained');
expect(!str_contains((string) Mailer::$lastError, $fakeKey), 'exact API key must never enter error text');
expect(!str_contains((string) Mailer::$lastError, 're_another_key'), 'key-shaped provider text must be redacted');
expect(Mailer::$lastMessageId === null, 'failed request must not expose a stale message id');

// Missing secret fails closed before making an HTTP request.
CurlMock::reset();
Config::$values['RESEND_API_KEY'] = '';
$ok = Mailer::send('recipient@example.test', 'Missing key', '<p>Missing key</p>');
expect(!$ok, 'missing API key must fail');
expect(Mailer::$lastError === 'Resend API key is not configured', 'missing-key error must not contain a credential');
expect(CurlMock::$url === '', 'no HTTP request may start without the key');

// Transport failure is sanitized as well.
CurlMock::reset();
Config::$values['RESEND_API_KEY'] = $fakeKey;
CurlMock::$response = false;
CurlMock::$error = 'connection failed with Bearer ' . $fakeKey;
$ok = Mailer::send('recipient@example.test', 'Network error', '<p>Network error</p>');
expect(!$ok, 'cURL failure must return false');
expect(!str_contains((string) Mailer::$lastError, $fakeKey), 'cURL error must redact the exact key');
expect(str_contains((string) Mailer::$lastError, '[REDACTED]'), 'redaction marker should remain diagnosable');

echo "Resend Mailer HTTP mock: PASS ({$assertions} assertions)\n";
