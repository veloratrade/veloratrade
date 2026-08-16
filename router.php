<?php
/**
 * VELORA — روتر محیط توسعه (فقط برای تست لوکال)
 * =====================================================================
 * این روتر همه‌چیز را برای محیط Development خودکار تنظیم می‌کند تا
 * هیچ‌وقت نسخه قدیمی فایل در مرورگر نمایش داده نشود:
 *
 *  ۱) ALL فایل‌ها (HTML/CSS/JS/تصویر/فونت/RSC) از طریق خود روتر سرو می‌شوند
 *     تا هدرهای ضد-کش واقعاً اعمال شوند (php -S برای فایل استاتیک هدر نمی‌دهد).
 *  ۲) Cache-Control: no-store, no-cache, must-revalidate  روی همه پاسخ‌ها
 *  ۳) Live Reload خودکار: هر ۱.۲ ثانیه تغییر فایل‌ها چک می‌شود و صفحه
 *     بدون کش رفرش می‌شود (برای HTML ها snippet تزریق می‌شود)
 *  ۴) Service Worker و کش‌های مرورگر در هر بار لود Unregister/پاک می‌شوند
 *  ۵) API روی /api/... همان بک‌اند واقعی است (که خودش no-store می‌فرستد)
 *
 * این رفتار فقط در Development فعال است. در Production (Apache) از .htaccess
 * استفاده می‌شود و کش/بهینه‌سازی عادی برقرار است.
 * =====================================================================
 */

// ---------- تشخیص محیط ----------
// به‌صورت پیش‌فرض Dev است. اگر فایل PRODUCTION در ریشه باشد یا
// متغیر محیطی VELORA_PROD=1 باشد، به حالت Production می‌رود.
$DEV = true;
if (is_file(__DIR__ . '/PRODUCTION') || getenv('VELORA_PROD') === '1') {
    $DEV = false;
}

// ---------- کمکی‌ها ----------
function velora_mime(string $ext): string
{
    $map = [
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
        'txt'  => 'text/plain; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'application/javascript; charset=utf-8', 'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8', 'map' => 'application/json; charset=utf-8',
        'png'  => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
        'eot'  => 'application/vnd.ms-fontobject',
        'mp4'  => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
        'xml'  => 'application/xml; charset=utf-8', 'pdf' => 'application/pdf',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

function velora_no_store(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/** امضای وضعیت فایل‌ها — برای تشخیص تغییر و Live Reload */
function velora_dev_sig(): string
{
    $dir = __DIR__;
    $parts = [];

    // HTML های ریشه و صفحات SPA (دایره ۱)
    foreach (glob($dir . '/*.html') ?: [] as $f) {
        $parts[] = filemtime($f) . ':' . filesize($f) . ':h:' . basename($f);
    }
    foreach (glob($dir . '/*/index.html') ?: [] as $f) {
        $parts[] = filemtime($f) . ':' . filesize($f) . ':p:' . basename(dirname($f));
    }
    // JS های ریشه (مثل enhancer)
    foreach (glob($dir . '/*.js') ?: [] as $f) {
        $parts[] = filemtime($f) . ':' . filesize($f) . ':j:' . basename($f);
    }
    // CSS ریشه
    foreach (glob($dir . '/*.css') ?: [] as $f) {
        $parts[] = filemtime($f) . ':' . filesize($f) . ':c:' . basename($f);
    }
    // همه PHP های بک‌اند
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/api/src', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $fi) {
            if ($fi->isFile() && $fi->getExtension() === 'php') {
                $parts[] = $fi->getMTime() . ':' . $fi->getSize() . ':p:' . $fi->getFilename();
            }
        }
    } catch (Throwable $e) {
        // نادیده
    }

    return md5(implode('|', $parts));
}

/** تزریق اسنیپت Dev (حذف SW/کش + Live Reload) به HTML */
function velora_inject_dev(string $html): string
{
    $snippet = <<<'JS'
<script data-velora-dev="1">
(function () {
  'use strict';
  /* ===== محیط توسعه — حذف کش و Live Reload (خودکار) ===== */
  try {
    // ۱) حذف Service Worker اگر وجود داشته باشد
    if (navigator.serviceWorker && navigator.serviceWorker.getRegistrations) {
      navigator.serviceWorker.getRegistrations().then(function (rs) {
        rs.forEach(function (r) { try { r.unregister(); } catch (e) {} });
      });
    }
    // ۲) پاک‌سازی کش‌های مرورگر
    if (window.caches && caches.keys) {
      caches.keys().then(function (ks) {
        ks.forEach(function (k) { try { caches.delete(k); } catch (e) {} });
      });
    }
  } catch (e) {}

  // ۳) Live Reload — هر ۱.۲ ثانیه وضعیت فایل‌ها را چک کن
  var sig = '';
  setInterval(function () {
    fetch('/velora-live-reload?sig=' + encodeURIComponent(sig), { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || typeof d.sig !== 'string') return;
        if (sig === '') { sig = d.sig; return; }   // اولین پاسخ = baseline
        if (d.changed) { window.location.reload(true); }  // فایل عوض شد → رفرش بدون کش
      })
      .catch(function () {});
  }, 1200);

  // ۴) نگهبان ضد-نسخه قدیمی: اگر لاگین داخلی React ظاهر شد (ریدایرکت کلاینت‌ساید
  //     از داشبورد/پروفایل و...)، فوراً به صفحه سفارشی /login ریدایرکت کامل بده.
  //     تصمیم بر اساس محتواست نه مسیر (چون React pathname را /login/ می‌کند).
  setInterval(function () {
    try {
      var t = document.body ? document.body.innerText : '';
      var isOldLogin  = t.indexOf('ورود با گوگل') !== -1 || t.indexOf('مرا به خاطر بسپار') !== -1;
      var isNewLogin  = t.indexOf('خوش برگشتید') !== -1;
      if (isOldLogin && !isNewLogin) {
        window.location.replace('/login');   // بارگذاری کامل → صفحه سفارشی سرو می‌شود
      }
    } catch (e) {}
  }, 900);
})();
</script>
JS;

    if (stripos($html, '</body>') !== false) {
        return str_ireplace('</body>', $snippet . '</body>', $html);
    }
    return $html . $snippet;
}

// =====================================================================
// ۱) API → بک‌اند واقعی (خودش no-store می‌فرستد)
// =====================================================================
$uri  = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path === '/health' || str_starts_with($path, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

// =====================================================================
// ۲) اندپوینت Live Reload
// =====================================================================
if ($path === '/velora-live-reload') {
    if (!$DEV) { http_response_code(404); return true; }
    velora_no_store();
    header('Content-Type: application/json; charset=utf-8');
    $sig    = velora_dev_sig();
    $client = (string) ($_GET['sig'] ?? '');
    echo json_encode(['sig' => $sig, 'changed' => ($client !== '' && $client !== $sig)]);
    return true;
}

// =====================================================================
// ۳) درخواست صفحه → همان resolver تولیدی cPanel/LiteSpeed
// =====================================================================
// HTML توسعه نیز از /localized/{locale} سرو می‌شود تا first paint، cookie و
// Accept-Language دقیقاً مانند production قابل‌آزمایش باشند.
$requestedExt = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if ($path === '/' || $requestedExt === 'html' || !str_contains(basename($path), '.')) {
    require __DIR__ . '/locale-router.php';
    return true;
}

// =====================================================================
// ۴) فایل استاتیک — از طریق روتر سرو می‌شود تا هدرها اعمال شوند
// =====================================================================
if ($path !== '/' && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . velora_mime($ext));
    if ($DEV) {
        velora_no_store();
    }
    if ($ext === 'html') {
        echo velora_inject_dev((string) file_get_contents($file));
    } else {
        readfile($file);
    }
    return true;
}

// =====================================================================
// ۴) مسیر دایرکتوری (صفحات SPA مثل /login، /dashboard)
// =====================================================================
if ($path !== '/' && is_dir($file)) {
    $inner = rtrim($file, '/') . '/index.html';
    if (is_file($inner)) {
        header('Content-Type: text/html; charset=utf-8');
        if ($DEV) {
            velora_no_store();
        }
        echo velora_inject_dev((string) file_get_contents($inner));
        return true;
    }
    // دایرکتوری بدون index.html → fallback به اسپلش
    if ($DEV) {
        header('Content-Type: text/html; charset=utf-8');
        velora_no_store();
        echo velora_inject_dev((string) file_get_contents(__DIR__ . '/index.html'));
        return true;
    }
}

// =====================================================================
// ۵) ریشه
// =====================================================================
if ($path === '/') {
    if (is_file(__DIR__ . '/index.html')) {
        header('Content-Type: text/html; charset=utf-8');
        if ($DEV) {
            velora_no_store();
        }
        echo velora_inject_dev((string) file_get_contents(__DIR__ . '/index.html'));
        return true;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>VELORA — router فعال است. index.html پیدا نشد.</h1>';
    return true;
}

// =====================================================================
// ۶) مسیرهای SPA بدون پوشه → اسپلش
// =====================================================================
if ($DEV && !str_contains($path, '.')) {
    header('Content-Type: text/html; charset=utf-8');
    velora_no_store();
    echo velora_inject_dev((string) file_get_contents(__DIR__ . '/index.html'));
    return true;
}

http_response_code(404);
echo '404';
return true;
