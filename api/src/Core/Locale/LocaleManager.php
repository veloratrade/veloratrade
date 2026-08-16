<?php

declare(strict_types=1);

namespace Velora\Core\Locale;

/**
 * Manifest-backed locale registry for out-of-band copy (for example email).
 *
 * REST resources never pass through this class: trades, prices, dates, symbols
 * and statistics stay language-neutral. Browser UI localization is handled by
 * public/assets/velora-localization.js using the same manifest/catalog source.
 */
final class LocaleManager
{
    private static ?self $instance = null;
    private array $manifest;
    /** @var array<string,array<string,string>> */
    private array $catalogs = [];
    private string $locale;

    private function __construct()
    {
        $manifestPath = dirname(__DIR__, 4) . '/public/locales/manifest.json';
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
        if (!is_array($manifest) || !isset($manifest['locales'], $manifest['defaultLocale'], $manifest['fallbackLocale'])) {
            throw new \RuntimeException('Locale manifest is missing or invalid.');
        }
        $this->manifest = $manifest;
        $this->locale = (string) $manifest['defaultLocale'];
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /** @param array<string,mixed> $context */
    public function init(array $context = []): void
    {
        $requested = $context['selected_language']
            ?? $context['user_language']
            ?? $this->fromAcceptLanguage((string) ($context['accept_language'] ?? ''))
            ?? $this->manifest['defaultLocale'];
        $this->setLanguage((string) $requested);
    }

    public function getLanguage(): string
    {
        return $this->locale;
    }

    public function setLanguage(string $locale): void
    {
        $this->locale = $this->normalize($locale);
    }

    public function isRTL(): bool
    {
        return $this->getDirection() === 'rtl';
    }

    public function getDirection(): string
    {
        return (string) ($this->manifest['locales'][$this->locale]['direction'] ?? 'ltr');
    }

    /** @return list<string> */
    public function getSupportedLanguages(): array
    {
        $enabled = [];
        foreach ($this->manifest['locales'] as $code => $metadata) {
            if (($metadata['enabled'] ?? true) !== false) {
                $enabled[] = (string) $code;
            }
        }
        return $enabled;
    }

    /** @return array<string,mixed> */
    public function getManifest(): array
    {
        return $this->manifest;
    }

    public function translate(string $key, array $params = []): string
    {
        return $this->translateFor($this->locale, $key, $params);
    }

    /** Translate out-of-band copy without mutating request-global locale state. */
    public function translateFor(string $locale, string $key, array $params = []): string
    {
        $locale = $this->normalize($locale);
        $message = $this->catalog($locale)[$key] ?? null;
        if ($message === null) {
            $message = $this->catalog((string) $this->manifest['fallbackLocale'])[$key] ?? $key;
        }
        foreach ($params as $name => $value) {
            $message = str_replace(['{' . $name . '}', ':' . $name], (string) $value, $message);
        }
        return $message;
    }

    public function directionFor(string $locale): string
    {
        $locale = $this->normalize($locale);
        return (string) ($this->manifest['locales'][$locale]['direction'] ?? 'ltr');
    }

    public function resolve(string $locale): string
    {
        return $this->normalize($locale);
    }

    public function supports(string $locale): bool
    {
        $candidate = str_replace('_', '-', trim($locale));
        $base = strtolower(explode('-', $candidate)[0] ?? '');
        foreach ($this->getSupportedLanguages() as $supported) {
            if (strcasecmp($candidate, $supported) === 0
                || ($base !== '' && strtolower(explode('-', $supported)[0]) === $base)) {
                return true;
            }
        }
        return false;
    }

    public function t(string $key, array $params = []): string
    {
        return $this->translate($key, $params);
    }

    /** @return array<string,string> */
    public function getAllTranslations(): array
    {
        return $this->catalog($this->locale);
    }

    public function getTranslationsJSON(): string
    {
        return json_encode($this->getAllTranslations(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function normalize(string $locale): string
    {
        $candidate = str_replace('_', '-', trim($locale));
        foreach ($this->getSupportedLanguages() as $supported) {
            if (strcasecmp($candidate, $supported) === 0) {
                return $supported;
            }
        }
        $base = strtolower(explode('-', $candidate)[0] ?? '');
        foreach ($this->getSupportedLanguages() as $supported) {
            if (strtolower(explode('-', $supported)[0]) === $base) {
                return $supported;
            }
        }
        return (string) $this->manifest['fallbackLocale'];
    }

    private function fromAcceptLanguage(string $header): ?string
    {
        foreach (explode(',', $header) as $part) {
            $candidate = trim(explode(';', $part)[0] ?? '');
            if ($candidate !== '') {
                /* The primary unsupported preference resolves to the manifest fallback locale. */
                return $this->normalize($candidate);
            }
        }
        return null;
    }

    /** @return array<string,string> */
    private function catalog(string $locale): array
    {
        if (isset($this->catalogs[$locale])) {
            return $this->catalogs[$locale];
        }
        $path = dirname(__DIR__, 4) . '/public/locales/' . basename($locale) . '.json';
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $messages = is_array($decoded) ? ($decoded['messages'] ?? []) : [];
        return $this->catalogs[$locale] = is_array($messages) ? $messages : [];
    }
}
