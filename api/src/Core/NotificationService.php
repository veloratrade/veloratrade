<?php

declare(strict_types=1);

namespace Velora\Core;

/**
 * سرویس جامع ارسال اعلان‌ها و ایمیل‌های VELORA.
 * طراحی‌شده به‌صورت کاملاً سبک، خوانا و سریع بدون هیچ‌گونه تصویر یا لوگوی خارجی در ایمیل‌ها (مناسب برای تمامی کلاینت‌ها).
 *
 * PR-05: تمام ایمیل‌های تراکنشی از catalog موجود (public/locales/{fa,en}.json)
 * محلی‌سازی می‌شوند. منبع اولیهٔ زبان، ترجیح ذخیره‌شدهٔ کاربر (users.locale) است؛
 * notificationLocale فقط به‌عنوان fallback برای مسیرهای بدون کاربرِ ذخیره‌شده
 * (ثبت‌نام تازه یا مسیرهای ضد-enumeration) به‌کار می‌رود.
 */
final class NotificationService
{
    private static function logNotification(?int $userId, string $email, string $type, string $subject, bool $sent): void
    {
        (new EmailNotificationRepository())->log(
            $userId,
            $email,
            $type,
            $subject,
            $sent ? 'sent' : 'failed',
            $sent ? null : (Mailer::$lastError !== null ? Mailer::$lastError : 'Unknown error')
        );
    }

    /** Send one rendered template with its dedicated CID icon. */
    private static function sendWithIcon(
        string $email,
        string $subject,
        string $html,
        string $iconName,
        string $eventType,
        ?int $userId,
    ): bool {
        $iconPath = dirname(__DIR__, 3) . '/public/assets/email-icons/' . $iconName . '.png';
        if (!is_file($iconPath) || !is_readable($iconPath)) {
            Mailer::$lastError = 'Email icon asset is missing';
            self::logNotification($userId, $email, $eventType, $subject, false);
            return false;
        }
        $sent = Mailer::sendWithInlineImages(
            $email,
            $subject,
            $html,
            ['velora-' . $iconName => $iconPath],
        );
        self::logNotification($userId, $email, $eventType, $subject, $sent);
        return $sent;
    }

    /**
     * PR-05: حل زبان ایمیل. ترجیح ذخیره‌شدهٔ کاربر (users.locale) منبع اولیه است و
     * hint درخواست (notificationLocale) فقط وقتی استفاده می‌شود که کاربرِ ذخیره‌شده
     * در دسترس نباشد. اگر هیچ‌کدام قابل استفاده نباشند null برمی‌گردد تا فراخواننده
     * به پیش‌فرض manifest (LocaleManager::getLanguage) بیفتد.
     */
    public static function resolveEmailLocale(?string $savedLocale, ?string $clientLocale): ?string
    {
        $i18n = \Velora\Core\Locale\LocaleManager::getInstance();
        foreach ([$savedLocale, $clientLocale] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && $i18n->supports($candidate)) {
                return $i18n->resolve($candidate);
            }
        }
        return null;
    }

    /**
     * استخراج نام تمیز کاربر؛ اگر نام خالی باشد یا همان آدرس ایمیل باشد، از عنوان
     * محلی‌سازی‌شدهٔ «دریافت‌کننده» (email.common.recipient) استفاده می‌شود.
     */
    private static function formatName(string $fullName, string $email, string $locale): string
    {
        $name = trim($fullName);
        if ($name === '' || mb_strtolower($name) === mb_strtolower(trim($email))) {
            return \Velora\Core\Locale\LocaleManager::getInstance()->translateFor($locale, 'email.common.recipient');
        }
        return $name;
    }

    /**
     * ۱. ایمیل تأیید ثبت‌نام
     */
    public static function sendVerificationEmail(string $email, string $fullName, string $verifyUrl, ?int $userId = null, ?string $notificationLocale = null): bool
    {
        $i18n = \Velora\Core\Locale\LocaleManager::getInstance();
        $lang = $notificationLocale ?? $i18n->getLanguage();
        $t = static fn (string $key, array $params = []): string => $i18n->translateFor($lang, $key, $params);

        $nameSafe = htmlspecialchars(self::formatName($fullName, $email, $lang), ENT_QUOTES, 'UTF-8');
        $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

        $subject = $t('email.verification.subject');

        $html = EmailTemplate::render(
            $t('email.verification.badge'),
            $t('email.verification.title'),
            '<p style="margin:0 0 14px;color:#ffffff;font-size:16px;font-weight:bold;">' . $t('email.common.greeting', ['name' => $nameSafe]) . '</p>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">' . $t('email.verification.intro') . '</p>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;background:#141f32;border:1px solid #d4af37;border-radius:10px;box-shadow:0 4px 15px rgba(212,175,55,0.15);">' .
            '<tr><td align="center" style="padding:14px 20px;font-family:Tahoma,Arial,sans-serif;font-size:16px;font-weight:bold;color:#d4af37;letter-spacing:0.5px;direction:ltr;">' . $emailSafe . '</td></tr>' .
            '</table>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">' . $t('email.verification.after') . '</p>',
            $t('email.verification.cta'),
            $verifyUrl,
            $t('email.verification.notice'),
            $t('email.common.subtitleSecurity'),
            $notificationLocale,
            'verification',
            $t('email.verification.badge')
        );

        return self::sendWithIcon($email, $subject, $html, 'verification', 'VERIFICATION_EMAIL', $userId);
    }

    /**
     * ۲. ایمیل خوش‌آمدگویی (حاوی باکس اختصاصی ایمیل کاربر)
     */
    public static function sendWelcomeEmail(string $email, string $fullName, string $dashboardUrl, ?int $userId = null, ?string $notificationLocale = null): bool
    {
        // BUG-A8: ایمیل خوش‌آمدگویی (غیرامنیتی) به ترجیح اعلان کاربر احترام می‌گذارد.
        // سیاست پیش‌فرض: در نبود رکورد ترجیح، ارسال مجاز است (رفتار فعلی repository).
        if ($userId !== null && !(new EmailPreferenceRepository())->canSend($userId, 'welcome')) {
            return false;
        }

        $i18n = \Velora\Core\Locale\LocaleManager::getInstance();
        $lang = $notificationLocale ?? $i18n->getLanguage();
        $t = static fn (string $key, array $params = []): string => $i18n->translateFor($lang, $key, $params);

        $nameSafe = htmlspecialchars(self::formatName($fullName, $email, $lang), ENT_QUOTES, 'UTF-8');
        $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $subject = $t('email.welcome.subject');

        $html = EmailTemplate::render(
            $t('email.welcome.badge'),
            $t('email.welcome.title'),
            '<p style="margin:0 0 14px;color:#ffffff;font-size:16px;font-weight:bold;">' . $t('email.common.greeting', ['name' => $nameSafe]) . '</p>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">' . $t('email.welcome.intro') . '</p>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;background:#141f32;border:1px solid #d4af37;border-radius:10px;box-shadow:0 4px 15px rgba(212,175,55,0.18);">' .
            '<tr><td align="center" style="padding:14px 20px;font-family:Tahoma,Arial,sans-serif;font-size:16px;font-weight:bold;color:#d4af37;letter-spacing:0.5px;direction:ltr;">' . $emailSafe . '</td></tr>' .
            '</table>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">' . $t('email.welcome.after') . '</p>',
            $t('email.welcome.cta'),
            $dashboardUrl,
            $t('email.welcome.notice'),
            $t('email.common.subtitleAnalytics'),
            $notificationLocale,
            'welcome',
            $t('email.welcome.badge')
        );

        return self::sendWithIcon($email, $subject, $html, 'welcome', 'WELCOME_EMAIL', $userId);
    }

    /**
     * ۳. ایمیل لینک بازیابی رمز عبور (Forgot Password)
     */
    public static function sendPasswordResetTokenEmail(string $email, string $fullName, string $resetUrl, ?int $userId = null, ?string $notificationLocale = null): bool
    {
        $i18n = \Velora\Core\Locale\LocaleManager::getInstance();
        $lang = $notificationLocale ?? $i18n->getLanguage();
        $t = static fn (string $key, array $params = []): string => $i18n->translateFor($lang, $key, $params);

        $nameSafe = htmlspecialchars(self::formatName($fullName, $email, $lang), ENT_QUOTES, 'UTF-8');
        $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $subject = $t('email.passwordReset.subject');

        $html = EmailTemplate::render(
            $t('email.passwordReset.badge'),
            $t('email.passwordReset.title'),
            '<p style="margin:0 0 14px;color:#ffffff;font-size:16px;font-weight:bold;">' . $t('email.common.greeting', ['name' => $nameSafe]) . '</p>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">' . $t('email.passwordReset.intro') . '</p>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;background:#141f32;border:1px solid #d4af37;border-radius:10px;box-shadow:0 4px 15px rgba(212,175,55,0.15);">' .
            '<tr><td align="center" style="padding:14px 20px;font-family:Tahoma,Arial,sans-serif;font-size:16px;font-weight:bold;color:#d4af37;letter-spacing:0.5px;direction:ltr;">' . $emailSafe . '</td></tr>' .
            '</table>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">' . $t('email.passwordReset.after') . '</p>',
            $t('email.passwordReset.cta'),
            $resetUrl,
            $t('email.passwordReset.notice'),
            $t('email.common.subtitleSecurity'),
            $notificationLocale,
            'password-reset',
            $t('email.passwordReset.badge')
        );

        return self::sendWithIcon($email, $subject, $html, 'password-reset', 'PASSWORD_RESET_LINK', $userId);
    }

    /**
     * ۴. ایمیل تغییر موفق رمز عبور (Password Changed Success)
     */
    public static function sendPasswordChangedEmail(string $email, string $fullName, ?int $userId = null, ?string $notificationLocale = null): bool
    {
        $i18n = \Velora\Core\Locale\LocaleManager::getInstance();
        $lang = $notificationLocale ?? $i18n->getLanguage();
        $t = static fn (string $key, array $params = []): string => $i18n->translateFor($lang, $key, $params);

        $nameSafe = htmlspecialchars(self::formatName($fullName, $email, $lang), ENT_QUOTES, 'UTF-8');
        $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $subject = $t('email.passwordChanged.subject');

        $html = EmailTemplate::render(
            $t('email.passwordChanged.badge'),
            $t('email.passwordChanged.title'),
            '<p style="margin:0 0 14px;color:#ffffff;font-size:16px;font-weight:bold;">' . $t('email.common.greeting', ['name' => $nameSafe]) . '</p>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">' . $t('email.passwordChanged.intro') . '</p>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;background:#141f32;border:1px solid #10b981;border-radius:10px;box-shadow:0 4px 15px rgba(16,185,129,0.15);">' .
            '<tr><td align="center" style="padding:14px 20px;font-family:Tahoma,Arial,sans-serif;font-size:16px;font-weight:bold;color:#10b981;letter-spacing:0.5px;direction:ltr;">' . $emailSafe . '</td></tr>' .
            '</table>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">' . $t('email.passwordChanged.after') . '</p>',
            null,
            null,
            $t('email.passwordChanged.notice'),
            $t('email.common.subtitleSecurity'),
            $notificationLocale,
            'password-changed',
            $t('email.passwordChanged.badge')
        );

        return self::sendWithIcon($email, $subject, $html, 'password-changed', 'PASSWORD_CHANGED', $userId);
    }

    /**
     * ۵. ایمیل هشدار ورود از دستگاه جدید (New Device Detected)
     */
    public static function sendNewDeviceDetectedEmail(
        string $email,
        string $fullName,
        string $ip,
        string $userAgent,
        string $time,
        ?int $userId = null,
        ?string $notificationLocale = null
    ): bool {
        if ($userId !== null && !(new EmailPreferenceRepository())->canSend($userId, 'security')) {
            return false;
        }

        $i18n = \Velora\Core\Locale\LocaleManager::getInstance();
        $lang = $notificationLocale ?? $i18n->getLanguage();
        $t = static fn (string $key, array $params = []): string => $i18n->translateFor($lang, $key, $params);

        $nameSafe = htmlspecialchars(self::formatName($fullName, $email, $lang), ENT_QUOTES, 'UTF-8');
        $ipSafe = htmlspecialchars($ip !== '' ? $ip : '0.0.0.0', ENT_QUOTES, 'UTF-8');
        $uaSafe = htmlspecialchars($userAgent !== '' ? $userAgent : $t('email.common.unknownDevice'), ENT_QUOTES, 'UTF-8');
        $timeSafe = htmlspecialchars($time, ENT_QUOTES, 'UTF-8');

        $subject = $t('email.newDevice.subject');
        $profileUrl = rtrim((string) Config::get('frontend_url', 'https://veloratrade.ir'), '/') . '/profile';

        $html = EmailTemplate::render(
            $t('email.newDevice.badge'),
            $t('email.newDevice.title'),
            '<p style="margin:0 0 14px;color:#ffffff;font-size:16px;font-weight:bold;">' . $t('email.common.greeting', ['name' => $nameSafe]) . '</p>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">' . $t('email.newDevice.intro') . '</p>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;background:#141f32;border-radius:10px;border:1px solid #d4af37;box-shadow:0 4px 15px rgba(212,175,55,0.15);">' .
            '<tr><td style="padding:16px 20px;color:#e2e8f0;font-size:14px;line-height:2.2;">' .
            '<strong style="color:#d4af37;">' . $t('email.newDevice.ip') . ':</strong> <span style="color:#ffffff;font-weight:bold;direction:ltr;display:inline-block;">' . $ipSafe . '</span><br>' .
            '<strong style="color:#d4af37;">' . $t('email.newDevice.device') . ':</strong> <span style="color:#ffffff;">' . $uaSafe . '</span><br>' .
            '<strong style="color:#d4af37;">' . $t('email.newDevice.time') . ':</strong> <span style="color:#ffffff;direction:ltr;display:inline-block;">' . $timeSafe . '</span>' .
            '</td></tr></table>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">' . $t('email.newDevice.after') . '</p>',
            $t('email.newDevice.cta'),
            $profileUrl,
            $t('email.newDevice.notice'),
            $t('email.common.subtitleSecurity'),
            $notificationLocale,
            'security',
            $t('email.newDevice.badge')
        );

        return self::sendWithIcon($email, $subject, $html, 'security', 'NEW_DEVICE_DETECTED', $userId);
    }

    /**
     * ۶. ایمیل ثبت اولین معامله (First Trade Recorded)
     */
    public static function sendFirstTradeEmail(
        string $email,
        string $fullName,
        string $symbol,
        string $direction,
        string $dashboardUrl,
        ?int $userId = null,
        ?string $notificationLocale = null
    ): bool {
        if ($userId !== null && !(new EmailPreferenceRepository())->canSend($userId, 'trades')) {
            return false;
        }

        $i18n = \Velora\Core\Locale\LocaleManager::getInstance();
        $lang = $notificationLocale ?? $i18n->getLanguage();
        $t = static fn (string $key, array $params = []): string => $i18n->translateFor($lang, $key, $params);

        $nameSafe = htmlspecialchars(self::formatName($fullName, $email, $lang), ENT_QUOTES, 'UTF-8');
        $symbolSafe = htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8');
        $dirLabel = strtolower($direction) === 'buy' ? $t('email.firstTrade.buy') : $t('email.firstTrade.sell');

        $subject = $t('email.firstTrade.subject');

        $html = EmailTemplate::render(
            $t('email.firstTrade.badge'),
            $t('email.firstTrade.title'),
            '<p style="margin:0 0 14px;color:#ffffff;font-size:16px;font-weight:bold;">' . $t('email.common.greeting', ['name' => $nameSafe]) . '</p>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">' . $t('email.firstTrade.intro') . '</p>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;background:#141f32;border-radius:10px;border:1px solid #d4af37;box-shadow:0 4px 15px rgba(212,175,55,0.15);">' .
            '<tr><td style="padding:16px 20px;color:#e2e8f0;font-size:14px;line-height:2.2;text-align:center;">' .
            '<div style="font-size:18px;font-weight:bold;color:#d4af37;margin-bottom:8px;">' . $symbolSafe . ' &nbsp;•&nbsp; ' . $dirLabel . '</div>' .
            '<div style="color:#ffffff;font-size:13px;">' . $t('email.firstTrade.status') . '</div>' .
            '</td></tr></table>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">' . $t('email.firstTrade.after') . '</p>',
            $t('email.firstTrade.cta'),
            $dashboardUrl,
            $t('email.firstTrade.notice'),
            $t('email.common.subtitleAnalytics'),
            $notificationLocale,
            'first-trade',
            $t('email.firstTrade.badge')
        );

        return self::sendWithIcon($email, $subject, $html, 'first-trade', 'FIRST_TRADE_RECORDED', $userId);
    }

    /**
     * ۷. ایمیل کسب دستاورد جدید (Achievement Unlocked)
     */
    public static function sendAchievementUnlockedEmail(
        string $email,
        string $fullName,
        string $achievementTitle,
        string $achievementDesc,
        string $profileUrl,
        ?int $userId = null,
        ?string $notificationLocale = null
    ): bool {
        if ($userId !== null && !(new EmailPreferenceRepository())->canSend($userId, 'achievements')) {
            return false;
        }

        $i18n = \Velora\Core\Locale\LocaleManager::getInstance();
        $lang = $notificationLocale ?? $i18n->getLanguage();
        $t = static fn (string $key, array $params = []): string => $i18n->translateFor($lang, $key, $params);

        $nameSafe = htmlspecialchars(self::formatName($fullName, $email, $lang), ENT_QUOTES, 'UTF-8');
        $titleSafe = htmlspecialchars(self::localizeCopy($achievementTitle, $notificationLocale, 'email.achievement.title'), ENT_QUOTES, 'UTF-8');
        $descSafe = htmlspecialchars(self::localizeCopy($achievementDesc, $notificationLocale, 'email.achievement.notice'), ENT_QUOTES, 'UTF-8');

        $subject = $t('email.achievement.subject');

        $html = EmailTemplate::render(
            $t('email.achievement.badge'),
            $t('email.achievement.title'),
            '<p style="margin:0 0 14px;color:#ffffff;font-size:16px;font-weight:bold;">' . $t('email.common.greeting', ['name' => $nameSafe]) . '</p>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">' . $t('email.achievement.intro') . '</p>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;background:#141f32;border-radius:10px;border:1px solid #d4af37;box-shadow:0 4px 15px rgba(212,175,55,0.18);">' .
            '<tr><td style="padding:18px 20px;text-align:center;">' .
            '<div style="font-size:18px;font-weight:bold;color:#d4af37;margin-bottom:8px;">' . $titleSafe . '</div>' .
            '<div style="color:#ffffff;font-size:14px;line-height:1.9;">' . $descSafe . '</div>' .
            '</td></tr></table>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">' . $t('email.achievement.after') . '</p>',
            $t('email.achievement.cta'),
            $profileUrl,
            $t('email.achievement.notice'),
            $t('email.common.subtitleAnalytics'),
            $notificationLocale,
            'achievement',
            $t('email.achievement.badge')
        );

        return self::sendWithIcon($email, $subject, $html, 'achievement', 'ACHIEVEMENT_UNLOCKED', $userId);
    }

    /**
     * BUG-A3: متنی که به‌صورت کلید i18n (مثل achievements.emailVerified.title) به
     * این لایه می‌رسد باید پیش از رندر ترجمه شود. اگر کلید در catalog وجود نداشته
     * باشد، به متن عمومیِ localized برمی‌گردد — کلید خام هرگز در HTML نهایی
     * ایمیل ظاهر نمی‌شود. متن عادی (غیرکلید) بدون تغییر برمی‌گردد.
     */
    private static function localizeCopy(string $value, ?string $locale, string $fallbackKey): string
    {
        // الگوی کلید catalog: بخش‌های الفبا‌عددی جدا‌شده با نقطه
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+$/', $value)) {
            return $value;
        }

        $i18n = \Velora\Core\Locale\LocaleManager::getInstance();
        $lang = $locale ?? $i18n->getLanguage();

        $translated = $i18n->translateFor($lang, $value);
        if ($translated !== $value) {
            return $translated;
        }

        // کلید ناشناخته: متن fallbackِ همان locale، نه کلید خام
        return $i18n->translateFor($lang, $fallbackKey);
    }
}
