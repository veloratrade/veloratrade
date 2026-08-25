<?php

declare(strict_types=1);

namespace Velora\AI\Services;

/**
 * Image optimization layer for AI providers.
 * Reduces token usage by resizing and compressing before sending to Gemini.
 * Uses GD (available on cPanel), no Imagick, no external libs.
 * Follows existing security patterns: temp files 0600, bounded output.
 */
final class ImageProcessor
{
    private const MAX_DIMENSION = 1024;
    private const JPEG_QUALITY = 80;
    private const MAX_BYTES = 2_097_152; // 2MB after processing, down from 8MB

    /**
     * Process image for AI: resize max dimension 1024px, compress JPEG 80, preserve readability.
     *
     * @param string $imageRaw Raw image bytes (PNG/JPEG/WebP)
     * @return array{data: string, mime: string, original_size: int, processed_size: int, resized: bool}
     */
    public static function process(string $imageRaw): array
    {
        $originalSize = strlen($imageRaw);
        $info = @getimagesizefromstring($imageRaw);

        if ($info === false) {
            // Not a valid image, return as is (will fail validation later)
            return [
                'data' => $imageRaw,
                'mime' => 'image/png',
                'original_size' => $originalSize,
                'processed_size' => $originalSize,
                'resized' => false,
            ];
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $type = (int) ($info[2] ?? IMAGETYPE_PNG);

        $mime = match ($type) {
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_WEBP => 'image/webp',
            default => 'image/png',
        };

        // If already small and under limit, return as is
        if ($width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION && $originalSize <= self::MAX_BYTES) {
            return [
                'data' => $imageRaw,
                'mime' => $mime,
                'original_size' => $originalSize,
                'processed_size' => $originalSize,
                'resized' => false,
            ];
        }

        // Try GD resize
        if (!function_exists('imagecreatefromstring') || !function_exists('imagescale') || !function_exists('imagejpeg')) {
            // GD not available, return original (fallback)
            return [
                'data' => $imageRaw,
                'mime' => $mime,
                'original_size' => $originalSize,
                'processed_size' => $originalSize,
                'resized' => false,
            ];
        }

        $src = @imagecreatefromstring($imageRaw);
        if ($src === false) {
            return [
                'data' => $imageRaw,
                'mime' => $mime,
                'original_size' => $originalSize,
                'processed_size' => $originalSize,
                'resized' => false,
            ];
        }

        // Calculate new dimensions preserving aspect ratio
        $scale = min(self::MAX_DIMENSION / max(1, $width), self::MAX_DIMENSION / max(1, $height), 1.0);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = $src;
        if ($scale < 1.0) {
            $resized = imagescale($src, $newWidth, $newHeight, IMG_BILINEAR_FIXED);
            if ($resized === false) {
                $resized = $src;
            } elseif ($resized !== $src) {
                imagedestroy($src);
            }
        }

        // Always convert to JPEG for better compression for AI (AI accepts JPEG)
        // Use temporary file to get bytes
        $tmp = tempnam(sys_get_temp_dir(), 'velora_img_');
        if ($tmp === false) {
            imagedestroy($resized);
            return [
                'data' => $imageRaw,
                'mime' => $mime,
                'original_size' => $originalSize,
                'processed_size' => $originalSize,
                'resized' => false,
            ];
        }

        @chmod($tmp, 0600);
        $success = @imagejpeg($resized, $tmp, self::JPEG_QUALITY);
        imagedestroy($resized);

        if (!$success || !is_file($tmp)) {
            @unlink($tmp);
            return [
                'data' => $imageRaw,
                'mime' => $mime,
                'original_size' => $originalSize,
                'processed_size' => $originalSize,
                'resized' => false,
            ];
        }

        $processed = file_get_contents($tmp);
        @unlink($tmp);

        if ($processed === false || $processed === '') {
            return [
                'data' => $imageRaw,
                'mime' => $mime,
                'original_size' => $originalSize,
                'processed_size' => $originalSize,
                'resized' => false,
            ];
        }

        // If processed is larger than original (unlikely for JPEG), keep original if under limit
        if (strlen($processed) > $originalSize && $originalSize <= self::MAX_BYTES) {
            return [
                'data' => $imageRaw,
                'mime' => $mime,
                'original_size' => $originalSize,
                'processed_size' => $originalSize,
                'resized' => false,
            ];
        }

        return [
            'data' => $processed,
            'mime' => 'image/jpeg', // we converted to JPEG
            'original_size' => $originalSize,
            'processed_size' => strlen($processed),
            'resized' => true,
        ];
    }

    /**
     * Quick check if image needs processing.
     */
    public static function needsProcessing(string $imageRaw): bool
    {
        $info = @getimagesizefromstring($imageRaw);
        if ($info === false) {
            return false;
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        return $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || strlen($imageRaw) > self::MAX_BYTES;
    }
}
