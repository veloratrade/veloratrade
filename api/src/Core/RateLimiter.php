<?php

declare(strict_types=1);

namespace Velora\Core;

use Velora\Core\Exceptions\ApiException;

/**
 * Rate limiter سبک (DB-based) — برای اندپوینت‌های حساس (لاگین/ثبت‌نام/بازیابی رمز).
 * هر «سطل» (bucket) = IP + عملیات؛ محدودیت: X تلاش در هر پنجره زمانی.
 * پس از عبور از حد، 429 TOO_MANY_REQUESTS با Retry-After برمی‌گردد.
 */
final class RateLimiter
{
    /**
     * @param string $bucket      کلید سطل، مثلاً 'login' (IP به‌صورت خودکار اضافه می‌شود)
     * @param int    $maxAttempts حداکثر تلاش در پنجره
     * @param int    $windowSec   طول پنجره (ثانیه)
     */
    public static function hit(string $bucket, int $maxAttempts = 10, int $windowSec = 300): void
    {
        $ip = self::clientIp();
        $key = $bucket . '|' . $ip;
        $pdo = Database::connection();
        $isSqlite = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';

        // Expire this bucket using its own window. A global cleanup based on the
        // caller's window would incorrectly shorten buckets that use a longer
        // policy (for example, registration's one-hour window).
        $pdo->prepare('DELETE FROM rate_limits WHERE bucket = :b AND window_start < :cutoff')
            ->execute([
                'b' => $key,
                'cutoff' => gmdate('Y-m-d H:i:s', time() - $windowSec),
            ]);
        // Bound stale storage independently of any individual bucket policy.
        $pdo->prepare('DELETE FROM rate_limits WHERE window_start < :stale')
            ->execute(['stale' => gmdate('Y-m-d H:i:s', time() - 172800)]);

        $now = gmdate('Y-m-d H:i:s', time());

        // اولین تلاش → درج؛ تلاش‌های بعدی → افزایش شمارنده (window_start حفظ می‌شود)
        if ($isSqlite) {
            $pdo->prepare(
                'INSERT INTO rate_limits (bucket, hits, window_start) VALUES (:b, 1, :w)
                 ON CONFLICT(bucket) DO UPDATE SET hits = hits + 1'
            )->execute(['b' => $key, 'w' => $now]);
        } else {
            $pdo->prepare(
                'INSERT INTO rate_limits (bucket, hits, window_start) VALUES (:b, 1, :w)
                 ON DUPLICATE KEY UPDATE hits = hits + 1'
            )->execute(['b' => $key, 'w' => $now]);
        }

        $stmt = $pdo->prepare('SELECT hits, window_start FROM rate_limits WHERE bucket = :b');
        $stmt->execute(['b' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false && (int) $row['hits'] > $maxAttempts) {
            $startedAt = strtotime((string) $row['window_start']) ?: time();
            header('Retry-After: ' . max(1, ($startedAt + $windowSec) - time()));
            throw new ApiException(
                'Too many requests.',
                429,
                'TOO_MANY_REQUESTS',
                null,
                'errors.rateLimited',
            );
        }
    }

    public static function clientIp(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            $remote = '0.0.0.0';
        }

        // Forwarding headers are attacker-controlled unless the immediate peer
        // is an explicitly configured reverse proxy.
        $trusted = Config::get('trusted_proxy_cidrs', []);
        if (is_array($trusted) && self::matchesAnyCidr($remote, $trusted)) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if (is_string($forwarded) && $forwarded !== '') {
                $first = trim(explode(',', $forwarded, 2)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return substr($first, 0, 64);
                }
            }
        }

        return substr($remote, 0, 64);
    }

    /** @param array<int,mixed> $cidrs */
    private static function matchesAnyCidr(string $ip, array $cidrs): bool
    {
        $packedIp = inet_pton($ip);
        if ($packedIp === false) {
            return false;
        }

        foreach ($cidrs as $cidr) {
            if (!is_string($cidr) || trim($cidr) === '') {
                continue;
            }
            [$network, $prefixRaw] = array_pad(explode('/', trim($cidr), 2), 2, null);
            $packedNetwork = inet_pton($network);
            if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedIp)) {
                continue;
            }
            $maxBits = strlen($packedIp) * 8;
            $prefix = $prefixRaw === null ? $maxBits : filter_var($prefixRaw, FILTER_VALIDATE_INT);
            if ($prefix === false || $prefix < 0 || $prefix > $maxBits) {
                continue;
            }

            $wholeBytes = intdiv((int) $prefix, 8);
            $remainingBits = (int) $prefix % 8;
            if ($wholeBytes > 0 && substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
                continue;
            }
            if ($remainingBits > 0) {
                $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
                if ((ord($packedIp[$wholeBytes]) & $mask) !== (ord($packedNetwork[$wholeBytes]) & $mask)) {
                    continue;
                }
            }
            return true;
        }

        return false;
    }
}
