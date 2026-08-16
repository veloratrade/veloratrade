<?php
declare(strict_types=1);
/**
 * VELORA — ابزار تشخیصی (diag)
 * این فایل را کنار index.php در پوشه api/ آپلود کنید و در مرورگر باز کنید.
 * خروجی آن را کپی کنید و برای من بفرستید.
 *
 * ⚠️ بعد از حل مشکل، حتماً این فایل را حذف کنید (اطلاعات را نشان می‌دهد).
 */
echo "<pre>";
echo "PHP version: " . PHP_VERSION . "\n\n";

$apiRoot = __DIR__;
echo "API root: {$apiRoot}\n";

// 1) آیا فایل .env وجود دارد؟
$envPath = $apiRoot . '/.env';
echo "\n1) فایل .env: " . (file_exists($envPath) ? "وجود دارد ✅" : "وجود ندارد ❌ ← این معمولاً مشکل اصلی است") . "\n";
if (file_exists($envPath)) {
    $raw = file_get_contents($envPath);
    $lines = array_filter(array_map('trim', explode("\n", $raw)), fn($l) => $l !== '' && !str_starts_with($l, '#'));
    foreach ($lines as $l) {
        [$k, $v] = array_pad(explode('=', $l, 2), 2, '');
        $show = in_array($k, ['DB_PASS', 'JWT_SECRET', 'APP_ENCRYPTION_KEY']) ? '********' : $v;
        echo "   {$k} = {$show}\n";
    }
}

// 2) putenv/getenv کار می‌کند؟
echo "\n2) putenv: " . (function_exists('putenv') ? 'موجود است' : 'وجود ندارد!') . "\n";
putenv('VELORA_TEST=1');
echo "   getenv بعد از putenv: '" . (getenv('VELORA_TEST') ?: 'خالی') . "'  ← (خالی یعنی putenv مشکل دارد)\n";

// 3) مقادیری که کد واقعاً استفاده می‌کند
require $apiRoot . '/src/bootstrap.php';
$cfg = \Velora\Core\Config::get('db', []);
echo "\n3) مقادیر دیتابیس که کد استفاده می‌کند:\n";
foreach ($cfg as $k => $v) {
    if ($k === 'pass') { $v = '********'; }
    echo "   {$k} = {$v}\n";
}

// 4) تست اتصال واقعی
echo "\n4) تست اتصال به دیتابیس:\n";
try {
    $pdo = \Velora\Core\Database::connection();
    echo "   اتصال برقرار شد ✅\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "   جدول‌ها: " . (count($tables) ? implode(', ', $tables) : "(هیچ جدولی نیست — schema.sql را import کنید)") . "\n";
    try {
        $users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        echo "   تعداد کاربران: {$users}\n";
    } catch (\Throwable $e) {
        echo "   جدول users خطا داد: " . $e->getMessage() . "\n";
    }
} catch (\Throwable $e) {
    echo "   خطای اتصال: " . $e->getMessage() . "\n";
}
echo "</pre>";
