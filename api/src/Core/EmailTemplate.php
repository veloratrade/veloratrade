<?php

declare(strict_types=1);

namespace Velora\Core;

use Velora\Core\Locale\LocaleManager;

/**
 * قالب استاندارد و ریسپانسیو ایمیل‌های پلتفرم VELORA
 * منطبق بر استانداردهای کلاینت‌های مدرن (Gmail, Outlook, Apple Mail) و قوانین آنتی‌اسپم.
 */
final class EmailTemplate
{
    public static function render(
        string $badge,
        string $title,
        string $contentHtml,
        ?string $buttonLabel = null,
        ?string $buttonUrl = null,
        ?string $notice = null,
        ?string $subtitle = null,
        ?string $locale = null,
    ): string {
        $i18n = LocaleManager::getInstance();
        $locale = $i18n->resolve($locale ?? $i18n->getLanguage());
        $direction = $i18n->directionFor($locale);
        $textAlign = $direction === 'rtl' ? 'right' : 'left';
        $noticeBorder = $direction === 'rtl' ? 'border-right' : 'border-left';
        $t = static fn (string $key, array $params = []): string => $i18n->translateFor($locale, $key, $params);

        $badgeSafe = self::escape($badge);
        $titleSafe = self::escape($title);
        $subtitleSafe = self::escape($subtitle ?? $t('email.common.subtitleSecurity'));

        $button = '';
        if ($buttonLabel !== null && $buttonUrl !== null) {
            $labelSafe = self::escape($buttonLabel);
            $urlSafe = self::escape($buttonUrl);
            $button = <<<HTML
<table role="presentation" align="center" width="230" height="48" cellspacing="0" cellpadding="0" border="0" style="width:230px;height:48px;margin:30px auto 10px;border-collapse:separate;">
<tr><td align="center" bgcolor="#d4af37" style="background:#d4af37;background-image:linear-gradient(135deg,#fce38a 0%,#d4af37 50%,#b88d1d 100%);border-radius:10px;box-shadow:0 6px 18px rgba(212,175,55,0.32);">
<a href="{$urlSafe}" style="display:block;padding:14px 18px;font-family:Tahoma,Arial,sans-serif;font-size:15px;font-weight:bold;color:#0b121e;text-decoration:none;text-shadow:0 1px 0 rgba(255,255,255,0.35);">{$labelSafe}</a>
</td></tr>
</table>
HTML;
        }

        $noticeHtml = '';
        if ($notice !== null && $notice !== '') {
            $noticeSafe = self::escape($notice);
            $noticeHtml = <<<HTML
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:28px;background:#141f32;border:1px solid #344460;{$noticeBorder}:4px solid #d4af37;border-radius:8px;">
<tr><td style="padding:15px 18px;color:#e2e8f0;font-size:13px;line-height:2.0;font-family:Tahoma,Arial,sans-serif;">{$noticeSafe}</td></tr>
</table>
HTML;
        }

        $platform = self::escape($t('email.common.footerPlatform'));
        $terms = self::escape($t('email.common.terms'));
        $privacy = self::escape($t('email.common.privacy'));
        $contact = self::escape($t('email.common.contact'));
        $rights = self::escape($t('email.common.rightsReserved'));
        $copyright = self::escape($t('email.common.copyright', ['year' => gmdate('Y')]));
        $langSafe = self::escape($locale);

        return <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{$langSafe}" dir="{$direction}">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="format-detection" content="telephone=no" />
<meta name="color-scheme" content="light dark" />
<title>{$titleSafe}</title>
</head>
<body style="margin:0;padding:0;background-color:#eef1f6;font-family:Tahoma,Arial,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
<table lang="{$langSafe}" dir="{$direction}" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#eef1f6;padding:32px 10px;font-family:Tahoma,Arial,sans-serif;">
<tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background:#0e1626;border:1px solid #26334d;border-radius:14px;overflow:hidden;box-shadow:0 12px 32px rgba(10,16,29,0.22);">
<tr><td align="center" style="padding:28px 25px 22px;background:#0a101d;border-bottom:1px solid #26334d;">
<a href="https://veloratrade.ir" style="text-decoration:none;display:inline-block;">
  <img src="https://veloratrade.ir/icon-192.png" width="48" height="48" alt="VELORA" style="display:block;margin:0 auto 10px;width:48px;height:48px;border:0;outline:none;" />
</a>
<div style="font-family:Arial,Tahoma,sans-serif;font-size:24px;line-height:28px;font-weight:bold;letter-spacing:4px;color:#d4af37;text-align:center;direction:ltr;">VELORA</div>
<div style="margin-top:6px;font-family:Arial,Tahoma,sans-serif;font-size:11px;letter-spacing:3px;color:#cbd5e1;text-align:center;direction:ltr;">{$subtitleSafe}</div>
</td></tr>
<tr><td style="padding:34px 32px;font-family:Tahoma,Arial,sans-serif;color:#f3f4f6;direction:{$direction};text-align:{$textAlign};">
<div style="margin:0 0 12px;color:#d4af37;font-size:13px;font-weight:bold;letter-spacing:0.5px;">▪ {$badgeSafe}</div>
<h1 style="margin:0 0 20px;font-size:24px;line-height:1.6;color:#ffffff;font-weight:bold;">{$titleSafe}</h1>
<div style="font-size:15px;line-height:2.1;color:#f3f4f6;">{$contentHtml}</div>
{$button}
{$noticeHtml}
</td></tr>
<tr><td align="center" style="padding:24px 20px;background:#090e18;border-top:1px solid #26334d;font-family:Tahoma,Arial,sans-serif;color:#9eabc0;">
<div style="font-family:Arial,Tahoma,sans-serif;font-size:12px;font-weight:bold;color:#e2e8f0;">{$copyright}</div>
<div style="margin-top:5px;font-size:11px;letter-spacing:.5px;color:#9eabc0;">{$platform}</div>
<div style="margin-top:14px;font-family:Arial,Tahoma,sans-serif;font-size:12px;direction:ltr;"><a href="https://veloratrade.ir" style="color:#d4af37;text-decoration:none;font-weight:bold;">veloratrade.ir</a><span style="color:#4a5568;"> &nbsp;|&nbsp; </span><a href="mailto:support@veloratrade.ir" style="color:#d4af37;text-decoration:none;">support@veloratrade.ir</a></div>
<div style="margin-top:12px;font-size:11px;line-height:1.9;"><a href="https://veloratrade.ir/support" style="color:#cbd5e1;text-decoration:none;">{$contact}</a><span style="color:#4a5568;"> &nbsp;•&nbsp; </span><a href="https://veloratrade.ir/terms" style="color:#cbd5e1;text-decoration:none;">{$terms}</a><span style="color:#4a5568;"> &nbsp;•&nbsp; </span><a href="https://veloratrade.ir/privacy" style="color:#cbd5e1;text-decoration:none;">{$privacy}</a></div>
<div style="margin-top:13px;font-size:10px;color:#718096;">{$rights}</div>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
