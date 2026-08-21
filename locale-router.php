<?php
declare(strict_types=1);

/**
 * VELORA localized HTML front controller for cPanel/LiteSpeed.
 *
 * Locale contract: an explicit supported locale URL prefix is authoritative; for
 * ordinary unprefixed navigation, readable manual-choice cookie -> primary
 * Accept-Language -> default. Unsupported browser languages use the manifest
 * fallback (English). Assets and APIs never pass here.
 */

$root = __DIR__;
$manifestPath = $root . '/public/locales/manifest.json';
$manifestRaw = @file_get_contents($manifestPath);
$manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
if (!is_array($manifest) || !isset($manifest['locales']) || !is_array($manifest['locales'])) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Localization manifest unavailable.';
    return true;
}

$enabled = [];
foreach ($manifest['locales'] as $code => $metadata) {
    if (is_array($metadata) && ($metadata['enabled'] ?? true) !== false) {
        $enabled[strtolower((string) $code)] = $metadata;
    }
}
$fallback = strtolower((string) ($manifest['fallbackLocale'] ?? 'en'));
$default = strtolower((string) ($manifest['defaultLocale'] ?? $fallback));
if (!isset($enabled[$fallback])) {
    $fallback = array_key_first($enabled) ?: 'en';
}
if (!isset($enabled[$default])) {
    $default = $fallback;
}

$normalize = static function (?string $candidate) use ($enabled): ?string {
    $value = strtolower(str_replace('_', '-', trim((string) $candidate)));
    if ($value === '') {
        return null;
    }
    if (isset($enabled[$value])) {
        return $value;
    }
    $base = explode('-', $value, 2)[0];
    return isset($enabled[$base]) ? $base : null;
};

$cookieKey = (string) ($manifest['cookieKey'] ?? 'velora_locale');
$cookieLocale = isset($_COOKIE[$cookieKey]) ? $normalize((string) $_COOKIE[$cookieKey]) : null;
$acceptLanguage = trim((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
$browserLocale = null;
if ($acceptLanguage !== '') {
    $primaryRange = trim(explode(',', $acceptLanguage, 2)[0]);
    $primaryTag = trim(explode(';', $primaryRange, 2)[0]);
    if ($primaryTag !== '' && $primaryTag !== '*') {
        $browserLocale = $normalize($primaryTag) ?? $fallback;
    }
}

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$requestPath = is_string($requestPath) ? rawurldecode($requestPath) : '/';
if (str_contains($requestPath, "\0") || preg_match('~(?:^|/)\.\.(?:/|$)~', $requestPath)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid request path.';
    return true;
}
$requestRelative = trim($requestPath, '/');
$routeParts = $requestRelative === '' ? [] : explode('/', $requestRelative, 2);
$routeCode = isset($routeParts[0]) ? strtolower(str_replace('_', '-', $routeParts[0])) : '';
$declaredRouteLocale = isset($enabled[$routeCode]) ? $routeCode : null;
if ($declaredRouteLocale !== null) {
    // A locale-prefixed URL is explicit navigation intent and provides stable,
    // crawlable SEO URLs. Unprefixed requests retain cookie -> browser priority.
    $locale = $declaredRouteLocale;
    $relative = $routeParts[1] ?? '';
    // F-03: refresh the manual-choice cookie from the explicit prefix so later
    // unprefixed navigation (e.g. /checkout/) keeps this locale. Behavior change,
    // documented: the cookie now records the latest explicit intent (prefixed URL
    // or manual switch) instead of manual switches only. Priority order for any
    // single request is unchanged (explicit prefix > cookie > Accept-Language >
    // default); attributes mirror velora-localization.js (Path=/, 1y, Lax).
    if ($cookieLocale !== $declaredRouteLocale && !headers_sent()) {
        setcookie($cookieKey, $declaredRouteLocale, [
            'expires' => time() + 31536000,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
    }
} else {
    $locale = $cookieLocale ?? $browserLocale ?? $default;
    $relative = $requestRelative;
}

if ($relative === '') {
    $relativeFile = 'index.html';
} elseif (str_ends_with($requestPath, '/')) {
    $relativeFile = $relative . '/index.html';
} elseif (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'html') {
    $relativeFile = $relative;
} elseif (pathinfo($relative, PATHINFO_EXTENSION) === '') {
    $relativeFile = $relative . '/index.html';
} else {
    $relativeFile = '__not_found__.html';
}

$localizedBase = realpath($root . '/localized/' . $locale);
$candidate = $localizedBase ? $localizedBase . '/' . $relativeFile : '';
$file = $candidate !== '' ? realpath($candidate) : false;
$found = is_string($file)
    && is_string($localizedBase)
    && str_starts_with($file, $localizedBase . DIRECTORY_SEPARATOR)
    && is_file($file);
if (!$found) {
    http_response_code(404);
    $fallback404 = $root . '/localized/' . $locale . '/404.html';
    $nested404 = $root . '/localized/' . $locale . '/404/index.html';
    $file = is_file($fallback404) ? $fallback404 : $nested404;
    if (!is_file($file)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo '404';
        return true;
    }
}

// Static HTML must not be delivered for authenticated application routes until
// a valid, non-revoked server session is present. This also prevents browser
// back/forward cache from making a logged-out dashboard shell usable.
$protectedRoutes = [
    'accounts/connect/index.html', 'admin/index.html', 'dashboard/index.html',
    'intelligence/index.html', 'markets/index.html', 'news/index.html',
    'performance/index.html', 'profile/index.html',
    'trades/index.html', 'trades/new/index.html', 'wallet/index.html',
];
if ($found && in_array($relativeFile, $protectedRoutes, true)) {
    $refreshToken = $_COOKIE['__Host-velora_refresh'] ?? null;
    $sessionIsValid = false;
    if (is_string($refreshToken) && $refreshToken !== '' && strlen($refreshToken) <= 128) {
        try {
            require_once $root . '/api/src/bootstrap.php';
            $pdo = \Velora\Core\Database::connection();
            $stmt = $pdo->prepare(
                'SELECT s.id FROM user_sessions s INNER JOIN users u ON u.id = s.user_id
                 WHERE s.refresh_token_hash = :hash
                   AND s.revoked_at IS NULL AND s.expires_at > :now AND u.status = :status
                 LIMIT 1'
            );
            $stmt->execute([
                'hash' => hash('sha256', $refreshToken),
                'now' => gmdate('Y-m-d H:i:s'),
                'status' => 'active',
            ]);
            $sessionIsValid = $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            // Fail closed: a config/database problem must not expose protected HTML.
            $sessionIsValid = false;
        }
    }
    if (!$sessionIsValid) {
        header('Cache-Control: no-store');
        header('Location: /' . $locale . '/login/', true, 302);
        return true;
    }
}

$failCsp = static function (): bool {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('Retry-After: 60');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    echo 'Security policy unavailable.';
    return true;
};
$cspManifestPath = $root . '/public/locales/csp-manifest.json';
$cspReleasePath = $root . '/localized/.csp-release.json';
$cspRaw = @file_get_contents($cspManifestPath);
$cspReleaseRaw = @file_get_contents($cspReleasePath);
$csp = is_string($cspRaw) ? json_decode($cspRaw, true) : null;
$cspRelease = is_string($cspReleaseRaw) ? json_decode($cspReleaseRaw, true) : null;
if (!is_array($csp)
    || !is_array($cspRelease)
    || ($csp['policyVersion'] ?? null) !== 2
    || ($csp['algorithm'] ?? null) !== 'sha256'
    || !isset($csp['routes'])
    || !is_array($csp['routes'])
    || !isset($cspRelease['cspManifestSha256'])
    || !is_string($cspRelease['cspManifestSha256'])
    || !hash_equals($cspRelease['cspManifestSha256'], hash('sha256', (string) $cspRaw))
    || ($cspRelease['policyVersion'] ?? null) !== ($csp['policyVersion'] ?? null)
    || ($cspRelease['releaseId'] ?? null) !== ($csp['releaseId'] ?? null)
    || ($cspRelease['releaseHtmlSha256'] ?? null) !== ($csp['releaseHtmlSha256'] ?? null)
    || ($cspRelease['routeCount'] ?? null) !== ($csp['routeCount'] ?? null)
    || ($csp['routeCount'] ?? null) !== count($csp['routes'])) {
    return $failCsp();
}

$localizedRoot = realpath($root . '/localized');
$servedRelative = is_string($localizedRoot)
    ? str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($file, strlen($localizedRoot)), DIRECTORY_SEPARATOR))
    : '';
$cspEntry = $csp['routes'][$servedRelative] ?? null;
$html = @file_get_contents($file);
if (!is_array($cspEntry)
    || !is_string($html)
    || ($cspEntry['file'] ?? null) !== $servedRelative
    || !isset($cspEntry['htmlSha256'])
    || !is_string($cspEntry['htmlSha256'])
    || !hash_equals($cspEntry['htmlSha256'], hash('sha256', $html))) {
    return $failCsp();
}
$validHashes = static function (mixed $values): ?array {
    if (!is_array($values) || array_values($values) !== $values) {
        return null;
    }
    $validated = [];
    foreach ($values as $value) {
        if (!is_string($value) || preg_match('~^sha256-[A-Za-z0-9+/]{43}=$~D', $value) !== 1) {
            return null;
        }
        $validated[$value] = true;
    }
    $result = array_keys($validated);
    sort($result, SORT_STRING);
    return $result === $values ? $result : null;
};
$scriptHashes = $validHashes($cspEntry['inlineScriptHashes'] ?? null);
$handlerHashes = $validHashes($cspEntry['eventHandlerHashes'] ?? null);
$styleHashes = $validHashes($cspEntry['inlineStyleHashes'] ?? null);
$styleAttributeHashes = $validHashes($cspEntry['styleAttributeHashes'] ?? null);
if ($scriptHashes === null
    || $handlerHashes === null
    || $styleHashes === null
    || $styleAttributeHashes === null) {
    return $failCsp();
}
$quoteHashes = static fn (array $hashes): string => implode(' ', array_map(
    static fn (string $hash): string => "'" . $hash . "'",
    $hashes,
));
$scriptElementPolicy = trim("'self' https://cdn.jsdelivr.net " . $quoteHashes($scriptHashes));
$scriptAttributePolicy = $handlerHashes === []
    ? "'none'"
    : trim("'unsafe-hashes' " . $quoteHashes($handlerHashes));
$isCheckoutRoute = in_array($servedRelative, ['fa/checkout/index.html', 'en/checkout/index.html'], true);
if ($isCheckoutRoute) {
    if ($styleAttributeHashes !== []
        || count($scriptHashes) !== 1
        || $handlerHashes !== []
        || count($styleHashes) !== 1
        || ($cspEntry['inlineScriptCount'] ?? null) !== 1
        || ($cspEntry['eventHandlerCount'] ?? null) !== 0
        || ($cspEntry['inlineStyleCount'] ?? null) !== 1
        || ($cspEntry['styleAttributeCount'] ?? null) !== 0) {
        return $failCsp();
    }
    $styleElementPolicy = trim("'self' https://fonts.googleapis.com " . $quoteHashes($styleHashes));
    $cspHeader = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; "
        . "form-action 'self'; img-src 'self' data: blob:; font-src 'self' data: https://fonts.gstatic.com; "
        . "style-src " . $styleElementPolicy . "; style-src-elem " . $styleElementPolicy . "; style-src-attr 'none'; "
        . "script-src " . $scriptElementPolicy . "; script-src-elem " . $scriptElementPolicy . "; "
        . "script-src-attr 'none'; worker-src 'self' blob: https://cdn.jsdelivr.net; "
        . "connect-src 'self' https://cdn.jsdelivr.net; media-src 'self' data: blob:; manifest-src 'self'; "
        . "upgrade-insecure-requests";
} else {
    $cspHeader = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; "
        . "form-action 'self'; img-src 'self' data: blob:; font-src 'self' data: https://fonts.gstatic.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "script-src 'self' 'wasm-unsafe-eval' https://cdn.jsdelivr.net; "
        . "script-src-elem " . $scriptElementPolicy . "; script-src-attr " . $scriptAttributePolicy . "; "
        . "worker-src 'self' blob: https://cdn.jsdelivr.net; connect-src 'self' https://cdn.jsdelivr.net; "
        . "media-src 'self' data: blob:; manifest-src 'self'; upgrade-insecure-requests";
}

$mtime = (int) filemtime($file);
$size = (int) filesize($file);
$etag = '"' . hash('sha256', $manifest['version'] . '|' . $locale . '|' . $relativeFile . '|' . $mtime . '|' . $size) . '"';
header('Content-Type: text/html; charset=utf-8');
header('Content-Language: ' . $locale);
header('X-VELORA-Locale: ' . $locale);
header('Vary: Cookie, Accept-Language');
// Protected pages contain account UI and must never be restored from browser
// back/forward cache after logout. Public localized pages may still revalidate.
header(in_array($relativeFile, $protectedRoutes, true)
    ? 'Cache-Control: no-store, max-age=0, must-revalidate'
    : 'Cache-Control: private, max-age=0, must-revalidate');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: ' . $cspHeader);

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    return true;
}

if (function_exists('velora_inject_dev') && isset($DEV) && $DEV === true) {
    // A post-build mutation would invalidate both the HTML digest and CSP hashes.
    return $failCsp();
}
header('Content-Length: ' . strlen($html));
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
    echo $html;
}
return true;
