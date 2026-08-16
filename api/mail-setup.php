<?php
declare(strict_types=1);
/**
 * VELORA — تنظیم ایمیل (صفحه وب ساده)
 * در مرورگر باز کنید، مقادیر SMTP را وارد کنید، ذخیره میشود.
 * ⚠️ بعد از تنظیم، این فایل را حذف کنید.
 */
header('Content-Type: text/html; charset=utf-8');

$envPath = __DIR__ . '/.env';
$message = '';
$saved = null;

function readEnv(string $path): array {
    $vars = [];
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $vars[trim($k)] = trim($v);
        }
    }
    return $vars;
}

function writeEnv(string $path, array $vars): bool {
    $content = "# VELORA .env\n";
    foreach ($vars as $k => $v) {
        $content .= "$k=$v\n";
    }
    return @file_put_contents($path, $content) !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vars = readEnv($envPath);
    $vars['MAIL_DRIVER'] = 'smtp';
    if (isset($_POST['MAIL_HOST']) && trim($_POST['MAIL_HOST']) !== '') $vars['MAIL_HOST'] = trim($_POST['MAIL_HOST']);
    if (isset($_POST['MAIL_PORT']) && trim($_POST['MAIL_PORT']) !== '') $vars['MAIL_PORT'] = trim($_POST['MAIL_PORT']);
    if (isset($_POST['MAIL_USER']) && trim($_POST['MAIL_USER']) !== '') $vars['MAIL_USER'] = trim($_POST['MAIL_USER']);
    if (isset($_POST['MAIL_PASS']) && trim($_POST['MAIL_PASS']) !== '') $vars['MAIL_PASS'] = trim($_POST['MAIL_PASS']);
    $vars['MAIL_FROM'] = 'no-reply@veloratrade.ir';
    $vars['MAIL_FROM_NAME'] = 'VELORA TRADE';

    if (writeEnv($envPath, $vars)) {
        $saved = true;
        $message = "✅ تنظیمات ذخیره شد! حالا mail-test.php را باز کنید.";
    } else {
        $message = "❌ نتوانستم .env را بنویسم — دسترسی پوشه را چک کنید (chmod 755).";
    }
}

$vars = readEnv($envPath);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تنظیم ایمیل VELORA</title>
<style>
body{font-family:Tahoma,sans-serif;background:#0d1524;color:#e6e9f0;margin:0;padding:24px}
.box{max-width:520px;margin:0 auto;background:#121826;border:1px solid #232c42;border-radius:14px;padding:26px}
h1{color:#8b7bf7;font-size:19px;margin:0 0 6px}
p.sub{color:#8b92a3;font-size:13px;margin:0 0 18px}
label{display:block;font-size:13px;margin:12px 0 4px;color:#c3c9d6}
input{width:100%;box-sizing:border-box;background:#0d1320;border:1px solid #2b3650;color:#fff;border-radius:8px;padding:10px;font-size:14px;direction:ltr}
input:focus{outline:none;border-color:#8b7bf7}
.btn{margin-top:18px;width:100%;padding:12px;border:0;border-radius:9px;background:#8b7bf7;color:#fff;font-weight:bold;font-size:15px;cursor:pointer}
.msg{margin-top:12px;padding:12px;border-radius:8px;font-size:13px}
.ok{background:#0d1320;border:1px solid #2e7d4f;color:#9fe8b4}
.err{background:#0d1320;border:1px solid #a33d3d;color:#e8a49f}
</style>
</head>
<body>
<div class="box">
  <h1>⚙️ تنظیم ایمیل</h1>
  <p class="sub">اطلاعات SMTP هاست را وارد کنید — خودکار در .env ذخیره میشود.</p>

  <?php if ($message): ?>
    <div class="msg <?= $saved ? 'ok' : 'err' ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <form method="post">
    <label>سرور SMTP</label>
    <input name="MAIL_HOST" value="<?= htmlspecialchars($vars['MAIL_HOST'] ?? 'mail.veloratrade.ir') ?>" required>

    <label>پورت (465 یا 587)</label>
    <input name="MAIL_PORT" value="<?= htmlspecialchars($vars['MAIL_PORT'] ?? '465') ?>" required>

    <label>نام کاربری (ایمیل کامل)</label>
    <input name="MAIL_USER" value="<?= htmlspecialchars($vars['MAIL_USER'] ?? 'no-reply@veloratrade.ir') ?>" required>

    <label>رمز ایمیل</label>
    <input name="MAIL_PASS" type="password" value="<?= htmlspecialchars($vars['MAIL_PASS'] ?? '') ?>" required>

    <button class="btn" type="submit">💾 ذخیره تنظیمات</button>
  </form>

  <p style="margin-top:14px;font-size:12px;color:#7d8698">
    بعد از ذخیره، <a href="mail-test.php" style="color:#8b7bf7">mail-test.php</a> را باز کنید تا تست شود.
  </p>
</div>
</body>
</html>
