<?php

declare(strict_types=1);

namespace Velora\AI\Security;

/**
 * Image anonymization for privacy — blurs top 15% where account numbers commonly exist.
 * Minimal GDPR-ready foundation, no overengineering.
 * Never stores original images, uses GD with 0600 temp files.
 *
 * FAIL-CLOSED: anonymize() returns null when anonymization cannot be guaranteed.
 * Callers MUST treat null as a hard failure and MUST NOT send the original
 * image to an external AI provider (local OCR fallback may still run).
 */
final class ImageAnonymizer
{
    private const BLUR_TOP_PERCENT = 15;

    /** @var bool|null Tracks the outcome of the last anonymize() call (null = never ran). */
    private static ?bool $lastResult = null;

    /**
     * Anonymize image by blurring the top region.
     *
     * @param string $imageRaw Raw image bytes
     * @return string|null Anonymized image bytes (JPEG), or null when anonymization
     *                     failed for any reason (empty/invalid input, missing GD,
     *                     undecodable image, too-small image, crop failure,
     *                     temp-file failure, encode failure, or identical output).
     */
    public static function anonymize(string $imageRaw): ?string
    {
        self::$lastResult = false;

        if ($imageRaw === '') {
            return null;
        }

        if (!function_exists('imagecreatefromstring') || !function_exists('imagefilter')) {
            // GD unavailable — cannot guarantee anonymization, so fail closed.
            return null;
        }

        $src = @imagecreatefromstring($imageRaw);
        if ($src === false) {
            return null;
        }

        $width = imagesx($src);
        $height = imagesy($src);

        if ($width < 10 || $height < 10) {
            imagedestroy($src);
            return null;
        }

        $blurHeight = max(1, (int) floor($height * self::BLUR_TOP_PERCENT / 100));

        // Create blurred top portion
        $top = imagecrop($src, ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $blurHeight]);
        if ($top === false) {
            imagedestroy($src);
            return null;
        }

        // Apply strong blur to top portion
        for ($i = 0; $i < 15; $i++) {
            imagefilter($top, IMG_FILTER_GAUSSIAN_BLUR);
        }
        // Also pixelate for extra anonymization
        imagefilter($top, IMG_FILTER_PIXELATE, 8, true);

        // Copy blurred top back onto original
        imagecopy($src, $top, 0, 0, 0, 0, $width, $blurHeight);
        imagedestroy($top);

        // Save as JPEG with 80 quality to temp file (0600)
        $tmp = tempnam(sys_get_temp_dir(), 'velora_anon_');
        if ($tmp === false) {
            imagedestroy($src);
            return null;
        }
        @chmod($tmp, 0600);

        $success = @imagejpeg($src, $tmp, 80);
        imagedestroy($src);

        if (!$success || !is_file($tmp)) {
            @unlink($tmp);
            return null;
        }

        $anonymized = file_get_contents($tmp);
        @unlink($tmp);

        if ($anonymized === false || $anonymized === '') {
            return null;
        }

        // Defense-in-depth: if processing somehow produced byte-identical output,
        // treat it as a failure rather than claiming anonymization succeeded.
        if (hash('sha256', $anonymized) === hash('sha256', $imageRaw)) {
            return null;
        }

        self::$lastResult = true;
        return $anonymized;
    }

    /**
     * Check if image likely contains sensitive top area (heuristic).
     * For now, always return true to enforce anonymization for external providers.
     */
    public static function shouldAnonymize(string $imageRaw): bool
    {
        // For MVP, anonymize all images sent to external AI
        // Future: detect if top area contains numbers via OCR
        return true;
    }

    /**
     * Get the REAL anonymization state of the most recent anonymize() call.
     *
     * @return array{anonymized: bool, method: string, top_percent: int, fail_closed: bool}
     */
    public static function getInfo(): array
    {
        $ok = self::$lastResult === true;
        return [
            'anonymized' => $ok,
            'method' => $ok ? 'blur_top_15_percent' : 'none',
            'top_percent' => $ok ? self::BLUR_TOP_PERCENT : 0,
            'fail_closed' => true,
        ];
    }
}
