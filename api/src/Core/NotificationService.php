<?php

declare(strict_types=1);

namespace Velora\Core;

/**
 * سرویس جامع ارسال اعلان‌ها و ایمیل‌های VELORA.
 * طراحی‌شده به‌صورت کاملاً سبک، خوانا و سریع بدون هیچ‌گونه تصویر یا لوگوی خارجی در ایمیل‌ها (مناسب برای تمامی کلاینت‌ها).
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
     * استخراج نام تمیز کاربر؛ در صورتی که نام خالی باشد یا همان آدرس ایمیل باشد، عبارت «کاربر گرامی» برمی‌گردد.
     */
    private static function formatName(string $fullName, string $email): string
    {
        $name = trim($fullName);
        if ($name === '' || mb_strtolower($name) === mb_strtolower(trim($email))) {
            return 'کاربر گرامی';
        }
        return $name;
    }

    /**
     * ۱. ایمیل تأیید ثبت‌نام
     */
    public static function sendVerificationEmail(string $email, string $fullName, string $verifyUrl, ?int $userId = null, ?string $notificationLocale = null): bool
    {
        $nameSafe = htmlspecialchars(self::formatName($fullName, $email), ENT_QUOTES, 'UTF-8');
        $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

        // BUG-A6: متن ایمیل از catalog محلی‌سازی و بر اساس locale کاربر ساخته می‌شود
        // (ترجمهٔ موجود استفاده می‌شود؛ هیچ ترجمهٔ جدیدی تکرار/اختراع نمی‌شود).
        $i18n = \Velora\Core\Locale\LocaleManager::getInstance();
        $lang = $notificationLocale ?? $i18n->getLanguage();
        $t = static fn (string $key, array $params = []): string => $i18n->translateFor($lang, $key, $params);

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
    public static function sendWelcomeEmail(string $email, string $fullName, string $dashboardUrl, ?int $userId = null): bool
    {
        // BUG-A8: ایمیل خوش‌آمدگویی (غیرامنیتی) به ترجیح اعلان کاربر احترام می‌گذارد.
        // سیاست پیش‌فرض: در نبود رکورد ترجیح، ارسال مجاز است (رفتار فعلی repository).
        if ($userId !== null && !(new EmailPreferenceRepository())->canSend($userId, 'welcome')) {
            return false;
        }

        $nameSafe = htmlspecialchars(self::formatName($fullName, $email), ENT_QUOTES, 'UTF-8');
        $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $subject = 'VELORA TRADE | Welcome (خوش‌آمدید)';

        $html = EmailTemplate::render(
            'حساب شما آماده است',
            'به VELORA TRADE خوش آمدید',
            '<p style="margin:0 0 14px;color:#ffffff;font-size:16px;font-weight:bold;">سلام ' . $nameSafe . '،</p>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">حساب کاربری شما با ایمیل زیر با موفقیت فعال شد:</p>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;background:#141f32;border:1px solid #d4af37;border-radius:10px;box-shadow:0 4px 15px rgba(212,175,55,0.18);">' .
            '<tr><td align="center" style="padding:14px 20px;font-family:Tahoma,Arial,sans-serif;font-size:16px;font-weight:bold;color:#d4af37;letter-spacing:0.5px;direction:ltr;">' . $emailSafe . '</td></tr>' .
            '</table>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">اکنون آماده ثبت معاملات، مدیریت ریسک و تحلیل حرفه‌ای عملکرد خود در پلتفرم VELORA TRADE هستید.</p>',
            'ورود به داشبورد',
            $dashboardUrl,
            'شروع مسیر حرفه‌ای معامله‌گری شما از همین‌جا است.',
            'TRADE · SMART ANALYTICS PLATFORM',
            null,
            'welcome',
            'خوش‌آمدگویی'
        );

        return self::sendWithIcon($email, $subject, $html, 'welcome', 'WELCOME_EMAIL', $userId);
    }

    /**
     * ۳. ایمیل لینک بازیابی رمز عبور (Forgot Password)
     */
    public static function sendPasswordResetTokenEmail(string $email, string $fullName, string $resetUrl, ?int $userId = null): bool
    {
        $nameSafe = htmlspecialchars(self::formatName($fullName, $email), ENT_QUOTES, 'UTF-8');
        $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $subject = 'VELORA TRADE | Password reset';

        $html = EmailTemplate::render(
            'بازیابی رمز عبور',
            'تغییر رمز عبور VELORA',
            '<p style="margin:0 0 14px;color:#ffffff;font-size:16px;font-weight:bold;">سلام ' . $nameSafe . '،</p>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">درخواست بازیابی رمز عبور برای حساب کاربری زیر دریافت شد:</p>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;background:#141f32;border:1px solid #d4af37;border-radius:10px;box-shadow:0 4px 15px rgba(212,175,55,0.15);">' .
            '<tr><td align="center" style="padding:14px 20px;font-family:Tahoma,Arial,sans-serif;font-size:16px;font-weight:bold;color:#d4af37;letter-spacing:0.5px;direction:ltr;">' . $emailSafe . '</td></tr>' .
            '</table>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">برای انتخاب رمز عبور جدید و ورود به حساب، روی دکمه زیر کلیک کنید:</p>',
            'تغییر رمز عبور',
            $resetUrl,
            'این لینک تا یک ساعت معتبر است. اگر این درخواست را شما ثبت نکرده‌اید، این ایمیل را نادیده بگیرید.',
            'TRADE · ACCOUNT SECURITY',
            null,
            'password-reset',
            'بازیابی رمز عبور'
        );

        return self::sendWithIcon($email, $subject, $html, 'password-reset', 'PASSWORD_RESET_LINK', $userId);
    }

    /**
     * ۴. ایمیل تغییر موفق رمز عبور (Password Changed Success)
     */
    public static function sendPasswordChangedEmail(string $email, string $fullName, ?int $userId = null): bool
    {
        $nameSafe = htmlspecialchars(self::formatName($fullName, $email), ENT_QUOTES, 'UTF-8');
        $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $subject = 'تغییر رمز عبور | VELORA TRADE';

        $html = EmailTemplate::render(
            'امنیت حساب',
            'رمز عبور شما تغییر کرد',
            '<p style="margin:0 0 14px;color:#ffffff;font-size:16px;font-weight:bold;">سلام ' . $nameSafe . '،</p>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">رمز عبور حساب کاربری زیر با موفقیت تغییر یافت:</p>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;background:#141f32;border:1px solid #10b981;border-radius:10px;box-shadow:0 4px 15px rgba(16,185,129,0.15);">' .
            '<tr><td align="center" style="padding:14px 20px;font-family:Tahoma,Arial,sans-serif;font-size:16px;font-weight:bold;color:#10b981;letter-spacing:0.5px;direction:ltr;">' . $emailSafe . '</td></tr>' .
            '</table>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">اگر این تغییر توسط شما انجام نشده است، لطفاً فوراً با تیم پشتیبانی تماس بگیرید و رمز عبور خود را بازنشانی کنید.</p>',
            null,
            null,
            'در جهت حفظ حداکثر امنیت حساب کاربری، تمامی نشست‌های فعال قبلی شما باطل شد.',
            'TRADE · ACCOUNT SECURITY',
            null,
            'password-changed',
            'تغییر رمز عبور'
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
        ?int $userId = null
    ): bool {
        if ($userId !== null && !(new EmailPreferenceRepository())->canSend($userId, 'security')) {
            return false;
        }

        $nameSafe = htmlspecialchars(self::formatName($fullName, $email), ENT_QUOTES, 'UTF-8');
        $ipSafe = htmlspecialchars($ip !== '' ? $ip : '0.0.0.0', ENT_QUOTES, 'UTF-8');
        $uaSafe = htmlspecialchars($userAgent !== '' ? $userAgent : 'دستگاه ناشناخته', ENT_QUOTES, 'UTF-8');
        $timeSafe = htmlspecialchars($time, ENT_QUOTES, 'UTF-8');

        $subject = 'هشدار ورود جدید | VELORA TRADE';
        $profileUrl = rtrim((string) Config::get('frontend_url', 'https://veloratrade.ir'), '/') . '/profile';

        $html = EmailTemplate::render(
            'هشدار ورود جدید',
            'ورود از دستگاه جدید',
            '<p style="margin:0 0 14px;color:#ffffff;font-size:16px;font-weight:bold;">سلام ' . $nameSafe . '،</p>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">یک ورود جدید به حساب کاربری شما در VELORA TRADE شناسایی شد:</p>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;background:#141f32;border-radius:10px;border:1px solid #d4af37;box-shadow:0 4px 15px rgba(212,175,55,0.15);">' .
            '<tr><td style="padding:16px 20px;color:#e2e8f0;font-size:14px;line-height:2.2;">' .
            '<strong style="color:#d4af37;">آی‌پی (IP):</strong> <span style="color:#ffffff;font-weight:bold;direction:ltr;display:inline-block;">' . $ipSafe . '</span><br>' .
            '<strong style="color:#d4af37;">دستگاه / مرورگر:</strong> <span style="color:#ffffff;">' . $uaSafe . '</span><br>' .
            '<strong style="color:#d4af37;">زمان ورود:</strong> <span style="color:#ffffff;direction:ltr;display:inline-block;">' . $timeSafe . '</span>' .
            '</td></tr></table>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">اگر این ورود توسط شما انجام شده است، نیازی به هیچ اقدامی نیست.</p>',
            'بررسی امنیت حساب',
            $profileUrl,
            'اگر شما وارد نشده‌اید، لطفاً فوراً رمز عبور خود را تغییر داده و نشست‌های فعال را لغو کنید.',
            'TRADE · ACCOUNT SECURITY',
            null,
            'security',
            'هشدار امنیتی'
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
        ?int $userId = null
    ): bool {
        if ($userId !== null && !(new EmailPreferenceRepository())->canSend($userId, 'trades')) {
            return false;
        }

        $nameSafe = htmlspecialchars(self::formatName($fullName, $email), ENT_QUOTES, 'UTF-8');
        $symbolSafe = htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8');
        $dirLabel = strtolower($direction) === 'buy' ? 'خرید (BUY)' : 'فروش (SELL)';

        $subject = 'اولین معامله | VELORA TRADE';

        $html = EmailTemplate::render(
            'ثبت اولین معامله',
            'اولین معامله شما در VELORA ثبت شد',
            '<p style="margin:0 0 14px;color:#ffffff;font-size:16px;font-weight:bold;">سلام ' . $nameSafe . '،</p>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">تبریک می‌گوییم! اولین معامله شما با موفقیت در ژورنال معاملاتی VELORA ثبت شد:</p>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;background:#141f32;border-radius:10px;border:1px solid #d4af37;box-shadow:0 4px 15px rgba(212,175,55,0.15);">' .
            '<tr><td style="padding:16px 20px;color:#e2e8f0;font-size:14px;line-height:2.2;text-align:center;">' .
            '<div style="font-size:18px;font-weight:bold;color:#d4af37;margin-bottom:8px;">' . $symbolSafe . ' &nbsp;•&nbsp; ' . $dirLabel . '</div>' .
            '<div style="color:#ffffff;font-size:13px;">وضعیت: ثبت موفق در ژورنال معاملاتی</div>' .
            '</td></tr></table>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">ثبت دقیق و منظم معاملات، اولین قدم در تحلیل حرفه‌ای عملکرد، بهبود مستمر استراتژی‌ها و کاهش ریسک معاملاتی است.</p>',
            'مشاهده تحلیل عملکرد',
            $dashboardUrl,
            'برای دریافت دقیق‌ترین شاخص‌ها و آمار معاملاتی، تمامی معاملات سودده و زیان‌ده خود را ثبت کنید.',
            'TRADE · SMART ANALYTICS PLATFORM',
            null,
            'first-trade',
            'اولین معامله'
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

        $nameSafe = htmlspecialchars(self::formatName($fullName, $email), ENT_QUOTES, 'UTF-8');
        $titleSafe = htmlspecialchars(self::localizeCopy($achievementTitle, $notificationLocale, 'email.achievement.title'), ENT_QUOTES, 'UTF-8');
        $descSafe = htmlspecialchars(self::localizeCopy($achievementDesc, $notificationLocale, 'email.achievement.notice'), ENT_QUOTES, 'UTF-8');

        $subject = 'دستاورد جدید | VELORA TRADE';

        $html = EmailTemplate::render(
            'دستاورد جدید',
            'دستاورد جدید باز شد!',
            '<p style="margin:0 0 14px;color:#ffffff;font-size:16px;font-weight:bold;">سلام ' . $nameSafe . '،</p>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">شما یک دستاورد جدید در مسیر حرفه‌ای معامله‌گری خود در پلتفرم VELORA TRADE کسب کردید:</p>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;background:#141f32;border-radius:10px;border:1px solid #d4af37;box-shadow:0 4px 15px rgba(212,175,55,0.18);">' .
            '<tr><td style="padding:18px 20px;text-align:center;">' .
            '<div style="font-size:18px;font-weight:bold;color:#d4af37;margin-bottom:8px;">' . $titleSafe . '</div>' .
            '<div style="color:#ffffff;font-size:14px;line-height:1.9;">' . $descSafe . '</div>' .
            '</td></tr></table>' .
            '<p style="margin:0 0 14px;color:#f3f4f6;">با ادامه ثبت معاملات و بهبود شاخص‌های معاملاتی، دستاوردهای بیشتری را در پنل کاربری خود آزاد کنید.</p>',
            'مشاهده دستاوردها',
            $profileUrl,
            'دستاوردهای شما نشان‌دهنده میزان انضباط، استمرار و رشد مهارت معاملاتی شما در VELORA است.',
            'TRADE · SMART ANALYTICS PLATFORM',
            $notificationLocale,
            'achievement',
            'دستاورد جدید'
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
