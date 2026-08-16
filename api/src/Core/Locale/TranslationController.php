<?php

declare(strict_types=1);

namespace Velora\Core\Locale;

use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Core\LocalizedResponse;

/**
 * VELORA — Translation Controller
 * 
 * API endpoints for managing translations
 */
class TranslationController
{
    /**
     * GET /api/v1/translations/{language}
     * Get translations for a specific language
     */
    public static function getTranslations(Request $request): array
    {
        $language = $request->param('language');
        
        if (!$language) {
            $locale = LocaleManager::getInstance();
            $language = $locale->getLanguage();
        }
        
        $locale = LocaleManager::getInstance();
        $locale->setLanguage($language);
        
        $translations = $locale->getAllTranslations();
        
        return LocalizedResponse::success([
            'language' => $language,
            'translations' => $translations,
        ]);
    }
    
    /**
     * GET /api/v1/translations
     * Get translations for current user's language
     */
    public static function getCurrentTranslations(Request $request): array
    {
        $locale = LocaleManager::getInstance();
        
        return LocalizedResponse::success([
            'language' => $locale->getLanguage(),
            'direction' => $locale->getDirection(),
            'is_rtl' => $locale->isRTL(),
            'translations' => $locale->getAllTranslations(),
        ]);
    }
    
    /**
     * GET /api/v1/translations/supported
     * Get list of supported languages
     */
    public static function getSupportedLanguages(Request $request): array
    {
        $locale = LocaleManager::getInstance();
        
        return LocalizedResponse::success([
            'languages' => [
                [
                    'code' => 'fa',
                    'name' => 'Persian',
                    'native_name' => 'فارسی',
                    'is_rtl' => true,
                    'is_default' => true,
                ],
                [
                    'code' => 'en',
                    'name' => 'English',
                    'native_name' => 'English',
                    'is_rtl' => false,
                    'is_default' => false,
                ],
            ],
            'current' => $locale->getLanguage(),
        ]);
    }
    
    /**
     * POST /api/v1/translations/preferred
     * Update user's preferred language
     */
    public static function updatePreferredLanguage(Request $request): array
    {
        $language = $request->json('language');
        
        if (!$language) {
            return LocalizedResponse::error('errors.bad_request');
        }
        
        $locale = LocaleManager::getInstance();
        
        if (!in_array($language, $locale->getSupportedLanguages())) {
            return LocalizedResponse::error('errors.bad_request');
        }
        
        // Update in database if user is authenticated
        $userId = $request->getAttribute('user_id');
        if ($userId) {
            LocaleMiddleware::updateUserLanguage($userId, $language);
        }
        
        // Update current session
        $locale->setLanguage($language);
        
        return LocalizedResponse::success([
            'language' => $language,
            'direction' => $locale->getDirection(),
            'is_rtl' => $locale->isRTL(),
        ], 'notifications.settings_saved');
    }
}
