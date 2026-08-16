<?php

declare(strict_types=1);

namespace Velora\Core\Locale;

use Velora\Core\Exceptions\ValidationException;
use Velora\Core\Request;
use Velora\Core\Response;

/**
 * Cache-only dynamic-content localization API.
 *
 * This endpoint intentionally does not call a provider and does not enqueue a
 * job. A cache miss is a valid result, allowing the original content to remain
 * on screen while ingestion and the CLI worker operate asynchronously.
 */
final class ContentTranslationController
{
    public function __construct(
        private readonly ContentTranslationRepository $translations = new ContentTranslationRepository(),
    ) {
    }

    public function lookup(Request $request): never
    {
        $targetLocale = self::locale((string) $request->input('targetLocale', ''));
        $inputItems = $request->input('items', []);
        $localeManager = LocaleManager::getInstance();
        if ($targetLocale === '' || !$localeManager->supports($targetLocale)) {
            throw new ValidationException('Unsupported target locale.', [
                'targetLocale' => [
                    'code' => 'UNSUPPORTED_LOCALE',
                    'messageKey' => 'errors.localization.unsupportedLocale',
                    'params' => [],
                ],
            ]);
        }
        if (!is_array($inputItems) || count($inputItems) > 100) {
            throw new ValidationException('Invalid translation cache lookup.', [
                'items' => [
                    'code' => 'INVALID_BATCH',
                    'messageKey' => 'errors.localization.invalidBatch',
                    'params' => ['max' => 100],
                ],
            ]);
        }
        $targetLocale = $localeManager->resolve($targetLocale);

        $items = [];
        $seen = [];
        foreach ($inputItems as $input) {
            if (!is_array($input)) {
                throw new ValidationException('Invalid translation cache item.', [
                    'items' => [
                        'code' => 'INVALID_ITEM',
                        'messageKey' => 'errors.localization.invalidItem',
                        'params' => [],
                    ],
                ]);
            }
            $contentType = trim((string) ($input['contentType'] ?? ''));
            $contentId = trim((string) ($input['contentId'] ?? ''));
            $sourceHash = trim((string) ($input['sourceHash'] ?? ''));
            if (
                !preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $contentType)
                || $contentId === '' || strlen($contentId) > 191
                || $sourceHash === '' || strlen($sourceHash) > 128
            ) {
                throw new ValidationException('Invalid translation cache identity.', [
                    'items' => [
                        'code' => 'INVALID_CONTENT_IDENTITY',
                        'messageKey' => 'errors.localization.invalidIdentity',
                        'params' => [],
                    ],
                ]);
            }
            $identity = $contentType . "\0" . $contentId . "\0" . $sourceHash;
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $items[] = ['contentType' => $contentType, 'contentId' => $contentId, 'sourceHash' => $sourceHash];
        }

        $translations = $this->translations->lookupReady($targetLocale, $items);
        Response::json([
            'targetLocale' => $targetLocale,
            'translations' => $translations,
            'misses' => count($items) - count($translations),
            'cacheOnly' => true,
        ]);
    }

    private static function locale(string $value): string
    {
        $value = str_replace('_', '-', trim($value));
        if (!preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/', $value)) {
            return '';
        }
        $parts = explode('-', $value);
        $locale = strtolower(array_shift($parts));
        foreach ($parts as $part) {
            $locale .= '-' . (strlen($part) === 2 ? strtoupper($part) : $part);
        }
        return $locale;
    }
}
