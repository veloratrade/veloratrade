<?php
declare(strict_types=1);
/**
 * VELORA — نصب‌کننده خودکار کامل (install.php)
 * ============================================
 * فقط یک بار در cPanel یک «کاربر MySQL» بسازید (۲ دقیقه) — این کار را نمی‌توان
 * خودکار کرد. بعد این فایل را باز کنید؛ خودش انجام می‌دهد:
 *   1) اتصال به MySQL با همان کاربر
 *   2) ساخت دیتابیس (خودکار — اگر هاست اجازه دهد)
 *   3) ساخت جدول‌ها (schema)
 *   4) ساخت کاربرهای admin و demo
 *   5) ساخت و پر کردن فایل .env
 *
 * ⚠️ بعد از نصب موفق، این فایل را حذف کنید!
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$API_ROOT = __DIR__;
$step = ($_SERVER['REQUEST_METHOD'] === 'POST') ? 'install' : 'form';
$result = null;

/* ---------- کمکی‌ها ---------- */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Split a SQL script into individual statements on top-level semicolons only.
 * The schema.sql contains string literals (e.g. COMMENT '...; ...') that may
 * themselves contain a semicolon. A naive explode(";") would cut the statement
 * mid-string and produce a syntax error, so we walk the script tracking quote
 * state ('...', "...", and `...` identifiers) and only split when outside them.
 */
function splitSqlStatements(string $sql): array
{
    $stmts = [];
    $buf = '';
    $n = strlen($sql);
    $quote = '';      // one of "'", '"', '`', or '' when outside a quote
    for ($i = 0; $i < $n; $i++) {
        $ch = $sql[$i];
        if ($quote !== '') {
            $buf .= $ch;
            // handle backslash escape inside quoted strings
            if ($ch === '\\' && $quote !== '`' && $i + 1 < $n) {
                $buf .= $sql[++$i];
                continue;
            }
            if ($ch === $quote) {
                $quote = ''; // close quote
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $buf .= $ch;
            continue;
        }
        if ($ch === ';') {
            $stmts[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') {
        $stmts[] = $buf;
    }
    return $stmts;
}

function runSchema(PDO $pdo, string $schemaFile): array
{
    $errors = [];
    $sql = file_get_contents($schemaFile);
    if ($sql === false) {
        return ['فایل database/schema.sql پیدا نشد — مطمئن شوید کنار این فایل است.'];
    }
    $sql = preg_replace('/CREATE\s+DATABASE[^;]*;/i', '', $sql);
    $sql = preg_replace('/USE\s+[^;]*;/i', '', $sql);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);     // کامنت‌های خط کامل (ممکن است سمیکالن داشته باشند)
    $sql = preg_replace('/\s+--[^\r\n]*/', ' ', $sql); // کامنت‌های انتهای خط

    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach (splitSqlStatements($sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || $stmt === "\n") continue;
        try {
            $pdo->exec($stmt);
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    return $errors;
}

function seedUsers(PDO $pdo): array
{
    try {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    } catch (\Throwable $e) {
        return ['جدول users ساخته نشده: ' . $e->getMessage()];
    }
    if ($count > 0) return [];
    $stmt = $pdo->prepare(
        "INSERT INTO users (email, password_hash, full_name, role, status)
         VALUES (:e1,:h1,'مدیر سیستم','admin','active'),
                (:e2,:h2,'کاربر نمایشی','user','active')"
    );
    $stmt->execute([
        'e1' => 'admin@velora.dev', 'h1' => password_hash('Admin123!', PASSWORD_BCRYPT, ['cost' => 12]),
        'e2' => 'demo@velora.dev',  'h2' => password_hash('Demo1234!', PASSWORD_BCRYPT, ['cost' => 12]),
    ]);
    return [];
}

function writeEnv(array $cfg): ?string
{
    $content = "# VELORA .env (تولید خودکار توسط install.php)\n"
        . "APP_ENV=prod\nAPP_DEBUG=false\n\n"
        . "JWT_SECRET=" . $cfg['jwt'] . "\n"
        . "APP_ENCRYPTION_KEY=" . $cfg['enc'] . "\n\n"
        . "DB_HOST=" . $cfg['host'] . "\n"
        . "DB_PORT=" . $cfg['port'] . "\n"
        . "DB_NAME=" . $cfg['name'] . "\n"
        . "DB_USER=" . $cfg['user'] . "\n"
        . "DB_PASS=" . $cfg['pass'] . "\n\n"
        . "CORS_ALLOWED_ORIGINS=" . ($cfg['cors'] !== '' ? $cfg['cors'] : '*') . "\n";
    if (@file_put_contents($cfg['envPath'], $content) === false) {
        return 'نمی‌توانم فایل .env را بنویسم — دسترسی نوشتن ندارم.';
    }
    return null;
}

/* ---------- اجرای نصب ---------- */
if ($step === 'install') {
    $host = trim($_POST['host'] ?? '127.0.0.1');
    $port = (int) ($_POST['port'] ?? 3306);
    $user = trim($_POST['user'] ?? '');
    $pass = (string) ($_POST['pass'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $cors = trim($_POST['cors'] ?? '');

    $lines = [];
    $ok = false;

    if ($user === '' || $name === '') {
        $lines[] = '❌ نام کاربر و نام دیتابیس را وارد کنید.';
    } else {
        // ---- ۱) اتصال به سرور MySQL (بدون دیتابیس) ----
        $pdo0 = null;
        try {
            $pdo0 = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
            $lines[] = "✅ اتصال به MySQL برقرار شد (کاربر: {$user})";
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Access denied') !== false) {
                $lines[] = "❌ نام کاربر یا رمز MySQL اشتباه است: Access denied";
                $lines[] = "→ در cPanel → MySQL Databases، کاربر را با رمز درست بسازید/چک کنید.";
            } elseif (stripos($msg, 'Unknown database') !== false) {
                // عجیب است، ولی برخی هاست‌ها بدون dbname خطا می‌دهند — ادامه می‌دهیم با dbname
                $lines[] = "ℹ️ اتصال بدون نام دیتابیس پشتیبانی نمی‌شود — تلاش با نام دیتابیس...";
            } else {
                $lines[] = "❌ خطای اتصال: " . $msg;
                $lines[] = "→ هاست/پورت را چک کنید (معمولاً 127.0.0.1:3306 یا localhost:3306).";
            }
        }

        // ---- ۲) ساخت دیتابیس (خودکار) ----
        $pdo = null;
        if ($pdo0 !== null) {
            try {
                $pdo0->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $lines[] = "✅ دیتابیس «{$name}» آماده شد (ساخته شد یا از قبل بود)";
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'Access denied') !== false || stripos($msg, 'CREATE command denied') !== false) {
                    $lines[] = "⚠️ کاربر شما اجازه ساخت دیتابیس ندارد.";
                    $lines[] = "→ در cPanel → MySQL Databases یک دیتابیس با نام دقیق «{$name}» بسازید";
                    $lines[] = "→ کاربر «{$user}» را با ALL PRIVILEGES به آن اضافه کنید";
                    $lines[] = "→ سپس همین صفحه را دوباره باز کنید (بدون تغییر چیزی).";
                } else {
                    $lines[] = "❌ خطا در ساخت دیتابیس: " . $msg;
                }
            }
        } else {
            // اتصال بدون دیتابیس نشد؛ با نام دیتابیس امتحان کن (برخی هاست‌ها)
            try {
                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
                $lines[] = "✅ اتصال مستقیم به دیتابیس «{$name}» برقرار شد (دیتابیس از قبل وجود داشت)";
            } catch (\Throwable $e2) {
                $lines[] = "❌ " . $e2->getMessage();
                $lines[] = "→ مطمئن شوید دیتابیس «{$name}» در cPanel ساخته شده و کاربر «{$user}» به آن دسترسی دارد.";
            }
        }

        // ---- ۳) ساخت جدول‌ها ----
        if ($pdo === null && $pdo0 !== null) {
            try {
                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } catch (\Throwable $e) {
                $lines[] = "❌ اتصال به دیتابیس جدید: " . $e->getMessage();
            }
        }

        if ($pdo !== null) {
            $schemaErrors = runSchema($pdo, $API_ROOT . '/database/schema.sql');
            if ($schemaErrors) {
                $lines[] = "❌ خطا در ساخت جدول‌ها:";
                foreach (array_slice($schemaErrors, 0, 4) as $se) $lines[] = "   - " . $se;
            } else {
                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                $lines[] = "✅ جدول‌ها ساخته شد: " . (count($tables) ? implode(', ', $tables) : '(خالی)');
            }

            // ---- ۴) کاربران پیش‌فرض ----
            $seedErrors = seedUsers($pdo);
            if ($seedErrors) {
                $lines[] = "❌ " . implode(' | ', $seedErrors);
            } else {
                $cnt = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
                $lines[] = "✅ کاربران آماده‌اند ({$cnt} کاربر): admin@velora.dev / Admin123! و demo@velora.dev / Demo1234!";
            }

            // ---- ۵) فایل .env ----
            $envErr = writeEnv([
                'envPath' => $API_ROOT . '/.env',
                'host' => $host, 'port' => (string)$port, 'name' => $name,
                'user' => $user, 'pass' => $pass, 'cors' => $cors,
                'jwt' => bin2hex(random_bytes(32)),
                'enc' => base64_encode(random_bytes(32)),
            ]);
            if ($envErr) {
                $lines[] = "❌ " . $envErr;
            } else {
                $lines[] = "✅ فایل .env ساخته شد";
                $ok = true;
            }
        }
    }

    if ($ok) {
        $lines[] = "";
        $lines[] = "🎉 نصب کامل شد! حالا وارد شوید:";
        $lines[] = "   ادمین:  admin@velora.dev  /  Admin123!";
        $lines[] = "   کاربر:  demo@velora.dev   /  Demo1234!";
        $lines[] = "⚠️ فایل install.php را حذف کنید!";
    }
    $result = ['ok' => $ok, 'lines' => $lines];
    $step = 'result';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>نصب خودکار VELORA</title>
<style>
  body { font-family: Tahoma, Vazirmatn, sans-serif; background:#0a0e17; color:#e6e9f0; margin:0; padding:24px; }
  .box { max-width:640px; margin:0 auto; background:#121826; border:1px solid #232c42; border-radius:14px; padding:28px; }
  h1 { font-size:20px; margin:0 0 4px; color:#f0b429; }
  p.sub { color:#8b92a3; font-size:13px; margin:0 0 20px; line-height:1.8; }
  label { display:block; font-size:13px; margin:14px 0 5px; color:#c3c9d6; }
  input { width:100%; box-sizing:border-box; background:#0d1320; border:1px solid #2b3650; color:#fff;
          border-radius:8px; padding:10px 12px; font-size:14px; }
  input:focus { outline:none; border-color:#f0b429; }
  .btn { margin-top:22px; width:100%; padding:12px; border:0; border-radius:9px; background:#f0b429;
         color:#141a26; font-weight:bold; font-size:15px; cursor:pointer; }
  .btn:hover { background:#ffc94d; }
  pre { background:#0d1320; border:1px solid #2b3650; border-radius:9px; padding:14px; font-size:12.5px;
        line-height:1.9; overflow-x:auto; white-space:pre-wrap; }
  .ok { border-color:#2e7d4f !important; }
  .err { border-color:#a33d3d !important; }
  .hint { font-size:12px; color:#7d8698; margin-top:16px; line-height:1.9; }
  .stepbox { background:#0d1320; border:1px solid #2b3650; border-radius:9px; padding:12px 14px; margin-top:12px; font-size:12.5px; color:#aeb6c6; line-height:1.8; }
</style>
</head>
<body>
<div class="box">
  <h1>⚙️ نصب خودکار VELORA</h1>
  <p class="sub">این فرم فقط <b>نام کاربر و رمز MySQL</b> را می‌گیرد — دیتابیس، جدول‌ها، کاربران و فایل .env را <b>خودش</b> می‌سازد.</p>

  <?php if ($step === 'form'): ?>
    <div class="stepbox">
      <b>قبل از شروع (یک‌بار):</b> در cPanel → MySQL® Databases یک <b>کاربر</b> بسازید
      (مثلاً نام کاربری خودتان، پسوند <code>_velora</code>). نیازی به ساخت دیتابیس نیست —
      همین نصب‌کننده خودش می‌سازد. نام کامل کاربر را یادداشت کنید (مثل <code>piknet_velora</code>).
    </div>

    <form method="post">
      <label>هاست MySQL (معمولاً 127.0.0.1)</label>
      <input name="host" value="127.0.0.1" required>

      <label>پورت (معمولاً 3306)</label>
      <input name="port" value="3306" type="number">

      <label>نام کاربر MySQL (با پیشوند — از cPanel کپی کنید، مثل piknet_velora)</label>
      <input name="user" placeholder="piknet_velora" required>

      <label>رمز عبور کاربر MySQL</label>
      <input name="pass" type="password" required>

      <label>نام دیتابیس (پیشنهاد: همان نام کاربر — مثل piknet_velora)</label>
      <input name="name" placeholder="piknet_velora" required>

      <label>دامنه فرانت‌اند (اختیاری — برای CORS، مثل https://veloratrade.ir)</label>
      <input name="cors" placeholder="https://veloratrade.ir">

      <button class="btn" type="submit">🚀 شروع نصب خودکار</button>
    </form>
    <div class="hint">
      💡 اگر کاربر «piknet_velora» را ساخته باشید، دیتابیس «piknet_velora» هم خودکار ساخته می‌شود
      (یوزرهای cPanel اجازه ساخت دیتابیس با پیشوند خودشان را دارند).
    </div>

  <?php elseif ($step === 'result'): ?>
    <pre class="<?= $result['ok'] ? 'ok' : 'err' ?>"><?= h(implode("\n", $result['lines'])) ?></pre>
    <?php if ($result['ok']): ?>
      <p style="margin-top:14px;font-size:13px;color:#9fe8b4">
        🎉 حالا به سایت بروید و با admin@velora.dev / Admin123! وارد شوید.<br>
        ⚠️ <b>فایل install.php را از روی هاست حذف کنید!</b>
      </p>
    <?php else: ?>
      <p style="margin-top:14px;font-size:13px;color:#e8a49f">
        طبق پیام بالا اقدام کنید و دوباره همین صفحه را باز کنید (فرم دوباره نمایش داده می‌شود).
      </p>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
