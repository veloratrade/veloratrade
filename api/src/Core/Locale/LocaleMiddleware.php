<?php

declare(strict_types=1);

namespace Velora\Core\Locale;

use Velora\Core\Request;

/**
 * VELORA — Locale Middleware
 * 
 * This middleware initializes the localization system for every request.
 * It detects the user's language and makes it available throughout the application.
 * 
 * Language Priority:
 * 1. User-selected language (from request header or query parameter)
 * 2. Language saved in user's account (database)
 * 3. Browser/system language (from Accept-Language header)
 * 4. Velora default language
 */
class LocaleMiddleware
{
    /**
     * Process the request and initialize localization
     * 
     * @param Request $request The request object
     * @return array Context array with language information
     */
    public static function process(Request $request): array
    {
        $locale = LocaleManager::getInstance();
        
        // Build context for language detection
        $context = self::buildContext($request);
        
        // Initialize the locale manager
        $locale->init($context);
        
        // Return context for use in controllers
        return [
            'language' => $locale->getLanguage(),
            'direction' => $locale->getDirection(),
            'is_rtl' => $locale->isRTL(),
            'locale' => $locale,
        ];
    }
    
    /**
     * Build context for language detection
     */
    private static function buildContext(Request $request): array
    {
        $context = [];
        
        // 1. Check for user-selected language (from request header)
        $selectedLanguage = $request->header('X-Velora-Language');
        if ($selectedLanguage) {
            $context['selected_language'] = $selectedLanguage;
        }
        
        // 2. Check for language in query parameter
        $queryLanguage = $request->query('lang');
        if ($queryLanguage) {
            $context['selected_language'] = $queryLanguage;
        }
        
        // 3. Check for language in user account (if authenticated)
        $userId = $request->getAttribute('user_id');
        if ($userId) {
            $context['user_id'] = $userId;
            $context['user_language'] = self::getUserLanguage($userId);
        }
        
        // 4. Check Accept-Language header
        $acceptLanguage = $request->header('Accept-Language');
        if ($acceptLanguage) {
            $context['accept_language'] = $acceptLanguage;
        }
        
        return $context;
    }
    
    /**
     * Get user's language from database
     */
    private static function getUserLanguage(int $userId): ?string
    {
        try {
            $db = \Velora\Core\Database::getInstance();
            $stmt = $db->prepare('SELECT language FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $result = $stmt->fetch();
            return $result ? $result['language'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Update user's language preference
     */
    public static function updateUserLanguage(int $userId, string $language): bool
    {
        try {
            $db = \Velora\Core\Database::getInstance();
            $stmt = $db->prepare('UPDATE users SET language = ? WHERE id = ?');
            return $stmt->execute([$language, $userId]);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Get language from request for API responses
     */
    public static function getResponseLanguage(Request $request): string
    {
        $locale = LocaleManager::getInstance();
        return $locale->getLanguage();
    }
    
    /**
     * Add language headers to response
     */
    public static function addResponseHeaders(array &$headers): void
    {
        $locale = LocaleManager::getInstance();
        
        $headers['Content-Language'] = $locale->getLanguage();
        $headers['X-Velora-Language'] = $locale->getLanguage();
        $headers['X-Velora-Direction'] = $locale->getDirection();
    }
}
