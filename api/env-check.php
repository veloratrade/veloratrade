<?php
declare(strict_types=1);
/**
 * VELORA — بررسی فایل .env
 * این فایل را در پوشه api/ آپلود کنید و در مرورگر باز کنید تا ببینید
 * بک‌اند دقیقاً از چه مقادیری استفاده می‌کند.
 * ⚠️ بعد از رفع مشکل، حذفش کنید.
 */
header('Content-Type: text/plain; charset=utf-8');

echo "=== مسیر فایل‌ها ===\n";
echo "API root: " . __DIR__ . "\n";
echo "Config path: " . __DIR__ . "/config/config.php\n";
echo "src path: " . __DIR__ . "/src\n";
echo "\n";

// 1) آیا .env وجود دارد؟
$candidates = [
    __DIR__ . '/.env',
    __DIR__ . '/public/.env',
    dirname(__DIR__) . '/.env',
];
echo "=== جستجوی .env ===\n";
foreach ($candidates as $c) {
    echo ($c) . " : " . (is_file($c) ? "✅ موجود" : "❌ نیست") . "\n";
}
echo "\n";

// 2) آیا putenv فعال است؟
echo "=== putenv ===\n";
echo (function_exists('putenv') ? "putenv موجود است" : "putenv نیست!") . "\n";
@putenv('VELORA_TEST=1');
echo "getenv بعد از putenv: '" . (getenv('VELORA_TEST') ?: 'خالی — یعنی putenv کار نمی‌کند') . "'\n";
echo "\n";

// 3) مقادیری که Config واقعاً استفاده می‌کند
require __DIR__ . '/src/bootstrap.php';
$cfg = \Velora\Core\Config::get('db', []);
echo "=== مقادیر دیتابیس (که بک‌اند استفاده می‌کند) ===\n";
foreach ($cfg as $k => $v) {
    if ($k === 'pass') { $v = '********'; }
    echo "  $k = $v\n";
}
echo "  FRONTEND_URL = " . \Velora\Core\Config::get('frontend_url', '(خالی)') . "\n";
echo "\n";

// 4) تست اتصال
echo "=== تست اتصال به دیتابیس ===\n";
try {
    $pdo = \Velora\Core\Database::connection();
    echo "  ✅ اتصال برقرار شد\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "  جدول‌ها: " . (count($tables) ? implode(', ', $tables) : "(هیچ)") . "\n";
} catch (\Throwable $e) {
    echo "  ❌ خطا: " . $e->getMessage() . "\n";
}
