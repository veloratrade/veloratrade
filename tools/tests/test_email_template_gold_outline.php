<?php

declare(strict_types=1);

namespace Velora\Core\Locale;

final class LocaleManager
{
    private static ?self $instance = null;
    public static function getInstance(): self { return self::$instance ??= new self(); }
    public function getLanguage(): string { return 'fa'; }
    public function resolve(string $locale): string { return in_array($locale, ['fa', 'en'], true) ? $locale : 'fa'; }
    public function directionFor(string $locale): string { return $locale === 'fa' ? 'rtl' : 'ltr'; }
    public function translateFor(string $locale, string $key, array $params = []): string
    {
        $values = [
            'email.common.subtitleSecurity' => 'ACCOUNT SECURITY',
            'email.common.footerPlatform' => 'SMART ANALYTICS PLATFORM',
            'email.common.terms' => 'Terms',
            'email.common.privacy' => 'Privacy',
            'email.common.contact' => 'Support',
            'email.common.rightsReserved' => 'All rights reserved.',
            'email.common.copyright' => '© ' . ($params['year'] ?? '2026') . ' VELORA TRADE',
        ];
        return $values[$key] ?? $key;
    }
}

namespace Velora\Core;

require dirname(__DIR__, 2) . '/api/src/Core/EmailTemplate.php';

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

$url = 'https://veloratrade.ir/fa/verify-email/#token=mock-token';
$html = EmailTemplate::render(
    'تأیید ایمیل',
    'ایمیل خود را تأیید کنید',
    '<p>سعید عزیز، حساب شما در انتظار تأیید است.</p>',
    'تأیید و فعال‌سازی حساب',
    $url,
    'این لینک ۲۴ ساعت معتبر است.',
    'ACCOUNT SECURITY',
    'fa',
);

expect(str_contains($html, 'width="620"'), 'email width must be 620px');
expect(str_contains($html, 'https://veloratrade.ir/public/assets/velora-email-logo.png'), 'real VELORA email logo must be used');
expect(!str_contains($html, 'icon-192.png'), 'legacy generic icon must not be used');
expect(str_contains($html, 'border:1px solid #9b782e'), 'outer Gold Outline border missing');
expect(str_contains($html, 'border:1px solid #3b465a'), 'inner Gold Outline border missing');
expect(str_contains($html, 'bgcolor="#101b2d"'), 'email-safe outer background missing');
expect(str_contains($html, 'bgcolor="#0d1829"'), 'email-safe inner background missing');
expect(str_contains($html, 'role="presentation"'), 'table presentation semantics missing');
expect(str_contains($html, 'dir="rtl"'), 'Persian direction must be RTL');
expect(str_contains($html, 'border-right:4px solid #d4af37'), 'RTL notice accent missing');
expect(str_contains($html, 'تأیید و فعال‌سازی حساب'), 'CTA label missing');
expect(substr_count($html, $url) === 1, 'CTA URL must appear exactly once; no helper/fallback link allowed');
expect(!str_contains($html, 'اگر دکمه'), 'helper-link copy must be absent');
expect(!str_contains(strtolower($html), 'if the button'), 'English helper-link copy must be absent');
expect(str_contains($html, 'display:none;max-height:0'), 'hidden inbox preheader missing');
expect(str_contains($html, 'https://veloratrade.ir/support'), 'shared footer support link missing');
expect(str_contains($html, 'https://veloratrade.ir/privacy'), 'shared footer privacy link missing');
expect(str_contains($html, 'https://veloratrade.ir/terms'), 'shared footer terms link missing');

$english = EmailTemplate::render('Security', 'New sign-in', '<p>Review this activity.</p>', 'Review account', 'https://veloratrade.ir/en/profile/', 'If this was not you, secure your account.', null, 'en');
expect(str_contains($english, 'dir="ltr"'), 'English direction must be LTR');
expect(str_contains($english, 'border-left:4px solid #d4af37'), 'LTR notice accent missing');

$withoutButton = EmailTemplate::render('Status', 'Account active', '<p>Ready.</p>', null, null, null, null, 'en');
expect(!str_contains($withoutButton, '<a href=""'), 'template without CTA must not render an empty link');

echo "EmailTemplate Gold Outline: PASS ({$assertions} assertions)\n";
