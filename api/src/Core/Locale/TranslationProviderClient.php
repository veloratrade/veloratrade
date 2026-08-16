<?php

declare(strict_types=1);

namespace Velora\Core\Locale;

use Velora\Core\Config;

/**
 * Provider adapter used exclusively by the CLI translation worker.
 * The provider endpoint is deployment-configured; no provider SDK is linked to
 * web requests and no locale-dependent business data enters this adapter.
 */
final class TranslationProviderClient
{
    private readonly string $url;
    private readonly string $token;
    private readonly string $providerName;

    public function __construct(?string $url = null, ?string $token = null, ?string $providerName = null)
    {
        $this->url = trim($url ?? Config::env('TRANSLATION_SERVICE_URL', ''));
        $this->token = trim($token ?? Config::env('TRANSLATION_SERVICE_TOKEN', ''));
        $this->providerName = trim($providerName ?? Config::env('TRANSLATION_SERVICE_NAME', 'external')) ?: 'external';
    }

    public function isConfigured(): bool
    {
        return $this->url !== '';
    }

    public function name(): string
    {
        return $this->providerName;
    }

    /**
     * @param array<string,string|null> $fields
     * @return array<string,string|null>
     */
    public function translate(string $sourceLocale, string $targetLocale, array $fields): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('TRANSLATION_SERVICE_URL is not configured.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The translation worker requires the PHP cURL extension.');
        }

        $payload = json_encode([
            'sourceLocale' => $sourceLocale,
            'targetLocale' => $targetLocale,
            'fields' => $fields,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $curl = curl_init($this->url);
        if ($curl === false) {
            throw new \RuntimeException('Could not initialise the translation provider request.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $transportError = curl_error($curl);
        curl_close($curl);

        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            throw new \RuntimeException(
                'Translation provider failed with HTTP ' . $status . ($transportError !== '' ? ': ' . $transportError : ''),
            );
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $translated = $decoded['fields'] ?? ($decoded['data']['fields'] ?? null);
        if (!is_array($translated)) {
            throw new \RuntimeException('Translation provider response does not contain fields.');
        }

        $result = [];
        foreach ($fields as $field => $_original) {
            $value = $translated[$field] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new \RuntimeException('Translation provider returned a non-string field.');
            }
            $result[$field] = $value;
        }
        return $result;
    }
}
