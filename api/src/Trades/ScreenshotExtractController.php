<?php

declare(strict_types=1);

namespace Velora\Trades;

use Velora\Core\RateLimiter;
use Velora\Core\Request;
use Velora\Core\Response;

final class ScreenshotExtractController
{
    private const MAX_IMAGES = 4;
    private const MAX_IMAGE_BYTES = 8_388_608;
    private const MAX_TOTAL_BYTES = 16_777_216;
    private const MAX_PIXELS = 12_000_000;
    private const PROCESS_TIMEOUT_SECONDS = 8.0;
    private const REQUEST_DEADLINE_SECONDS = 30.0;
    private const MAX_PROCESS_OUTPUT = 262_144;

    private float $deadline = 0.0;

    public function extract(Request $request): never
    {
        $bin = $this->tesseractBin();
        if ($bin === null || !function_exists('proc_open')) {
            Response::error('OCR engine is not available on this host.', 501, 'OCR_UNAVAILABLE');
        }

        // Fail closed: if the limiter storage is unavailable, this endpoint must
        // not fall through to resource-intensive OCR work.
        $userId = (int) ($request->attributes['user_id'] ?? 0);
        RateLimiter::hit('screenshot-ocr-user-' . $userId, 8, 300);

        $images = $request->body['images'] ?? null;
        if (!is_array($images) || $images === []) {
            Response::error('images[] is required.', 422, 'VALIDATION_FAILED');
        }
        if (count($images) > self::MAX_IMAGES || array_keys($images) !== range(0, count($images) - 1)) {
            Response::error('Maximum 4 screenshots per request.', 422, 'VALIDATION_FAILED');
        }

        $decoded = [];
        $totalBytes = 0;
        foreach ($images as $dataUrl) {
            if (!is_string($dataUrl)) {
                Response::error('Invalid image payload.', 422, 'VALIDATION_FAILED');
            }
            $raw = $this->decodeAndValidateImage($dataUrl);
            $totalBytes += strlen($raw);
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                Response::error('Combined image payload is too large.', 422, 'VALIDATION_FAILED');
            }
            $decoded[] = $raw;
        }

        $this->deadline = microtime(true) + self::REQUEST_DEADLINE_SECONDS;
        $texts = [];
        foreach ($decoded as $raw) {
            if (microtime(true) >= $this->deadline) {
                Response::error('OCR processing timed out.', 503, 'OCR_TIMEOUT');
            }
            $texts[] = $this->ocrOne($bin, $raw);
        }

        $times = $this->readTimesFromFirstImage($decoded[0]);

        Response::json([
            'engine' => 'tesseract-system',
            'texts' => $texts,
            'times' => $times,
        ]);
    }

    private function decodeAndValidateImage(string $dataUrl): string
    {
        $comma = strpos($dataUrl, ',');
        if ($comma === false) {
            Response::error('Invalid image payload.', 422, 'VALIDATION_FAILED');
        }
        $header = substr($dataUrl, 0, $comma + 1);
        $declaredTypes = [
            'data:image/png;base64,' => IMAGETYPE_PNG,
            'data:image/jpeg;base64,' => IMAGETYPE_JPEG,
            'data:image/webp;base64,' => IMAGETYPE_WEBP,
        ];
        if (!isset($declaredTypes[$header])) {
            Response::error('Only PNG, JPEG, and WebP screenshots are accepted.', 422, 'VALIDATION_FAILED');
        }

        $encoded = substr($dataUrl, $comma + 1);
        if ($encoded === '' || strlen($encoded) > (int) ceil(self::MAX_IMAGE_BYTES * 4 / 3) + 4) {
            Response::error('Image is too large.', 422, 'VALIDATION_FAILED');
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || $raw === '' || strlen($raw) > self::MAX_IMAGE_BYTES) {
            Response::error('Invalid image payload.', 422, 'VALIDATION_FAILED');
        }

        $info = @getimagesizefromstring($raw);
        if ($info === false || (int) ($info[2] ?? 0) !== $declaredTypes[$header]) {
            Response::error('Image content does not match its declared type.', 422, 'VALIDATION_FAILED');
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 32 || $height < 32 || $width > 6000 || $height > 6000
            || ($width * $height) > self::MAX_PIXELS) {
            Response::error('Image dimensions are not supported.', 422, 'VALIDATION_FAILED');
        }

        return $raw;
    }

    private function readTimesFromFirstImage(string $raw): array
    {
        $empty = ['openTime' => '', 'closeTime' => ''];
        $script = dirname(__DIR__, 2) . '/workers/read_mt5_times.py';
        if (!is_file($script) || microtime(true) >= $this->deadline) {
            return $empty;
        }

        $tmp = $this->temporaryPath('velora_time_');
        try {
            if (file_put_contents($tmp, $raw, LOCK_EX) === false) {
                return $empty;
            }
            @chmod($tmp, 0600);
            $json = $this->runProcess(['python3', $script, $tmp], 65_536);
        } finally {
            @unlink($tmp);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $empty;
        }
        return [
            'openTime' => $this->validExtractedTime($data['openTime'] ?? null),
            'closeTime' => $this->validExtractedTime($data['closeTime'] ?? null),
        ];
    }

    private function validExtractedTime(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 32) {
            return '';
        }
        return preg_match('/\A20\d{2}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?\z/D', $value) ? $value : '';
    }

    private function tesseractBin(): ?string
    {
        foreach (['/usr/bin/tesseract', '/usr/local/bin/tesseract', '/bin/tesseract'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    private function ocrOne(string $bin, string $raw): string
    {
        $src = $this->temporaryPath('velora_ocr_src_');
        $prep = $this->temporaryPath('velora_ocr_prep_');
        try {
            if (file_put_contents($src, $raw, LOCK_EX) === false) {
                return '';
            }
            @chmod($src, 0600);
            @chmod($prep, 0600);
            $this->prepareImage($src, $prep);
            $input = filesize($prep) > 0 ? $prep : $src;

            $hasFas = is_file('/usr/share/tesseract-ocr/5/tessdata/fas.traineddata')
                || is_file('/usr/share/tesseract-ocr/4.00/tessdata/fas.traineddata')
                || is_file('/usr/share/tessdata/fas.traineddata');
            $eng = $this->runTess($bin, $input, 'eng');
            $fas = $hasFas ? $this->runTess($bin, $input, 'fas+eng') : '';
            $dates = $this->ocrDateBands($bin, $input, $hasFas);
            return $this->boundedUtf8(trim($eng . "\n" . $fas . "\n" . $dates), self::MAX_PROCESS_OUTPUT);
        } finally {
            @unlink($src);
            @unlink($prep);
        }
    }

    private function ocrDateBands(string $bin, string $input, bool $hasFas): string
    {
        if (!function_exists('imagecreatefromstring') || microtime(true) >= $this->deadline) {
            return '';
        }
        $blob = @file_get_contents($input);
        if ($blob === false) {
            return '';
        }
        $im = @imagecreatefromstring($blob);
        if ($im === false) {
            return '';
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $x = (int) floor($w * 0.46);
        $crop = imagecrop($im, ['x' => $x, 'y' => 0, 'width' => max(12, $w - $x), 'height' => $h]);
        imagedestroy($im);
        if ($crop === false) {
            return '';
        }

        $cw = imagesx($crop);
        $ch = imagesy($crop);
        $scale = min(2.0, 2200 / max(1, $cw), 3000 / max(1, $ch));
        $nw = max(1, (int) floor($cw * $scale));
        $nh = max(1, (int) floor($ch * $scale));
        $big = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($big, $crop, 0, 0, 0, 0, $nw, $nh, $cw, $ch);
        imagefilter($big, IMG_FILTER_GRAYSCALE);
        imagefilter($big, IMG_FILTER_NEGATE);
        imagefilter($big, IMG_FILTER_CONTRAST, -35);
        $path = $this->temporaryPath('velora_ocr_right_');
        try {
            imagepng($big, $path, 6);
            @chmod($path, 0600);
        } finally {
            imagedestroy($crop);
            imagedestroy($big);
        }
        try {
            return $this->runTessPsm($bin, $path, $hasFas ? 'fas+eng' : 'eng', 6);
        } finally {
            @unlink($path);
        }
    }

    private function runTessPsm(string $bin, string $input, string $lang, int $psm): string
    {
        return $this->runProcess([
            $bin, $input, 'stdout', '-l', $lang, '--psm', (string) $psm, '--oem', '1',
        ]);
    }

    private function runTess(string $bin, string $input, string $lang): string
    {
        return $this->runTessPsm($bin, $input, $lang, 6);
    }

    private function prepareImage(string $src, string $dest): void
    {
        if (!function_exists('imagecreatefromstring')) {
            $this->prepareWithMagick($src, $dest);
            return;
        }
        $blob = @file_get_contents($src);
        if ($blob === false) {
            return;
        }
        $im = @imagecreatefromstring($blob);
        if ($im === false) {
            $this->prepareWithMagick($src, $dest);
            return;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $targetW = ($w < 900 || $h < 280) ? 1800 : min(1600, $w);
        $scale = min($targetW / max(1, $w), 2400 / max(1, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagefilter($out, IMG_FILTER_GRAYSCALE);
        imagefilter($out, IMG_FILTER_CONTRAST, -22);
        imagepng($out, $dest, 6);
        @chmod($dest, 0600);
        imagedestroy($im);
        imagedestroy($out);
    }

    private function prepareWithMagick(string $src, string $dest): void
    {
        $convert = is_executable('/usr/bin/convert') ? '/usr/bin/convert' : null;
        if ($convert === null) {
            return;
        }
        $this->runProcess([
            $convert, $src, '-colorspace', 'Gray', '-resize', '1600x1600>',
            '-normalize', '-contrast-stretch', '2%x2%', $dest,
        ], 4_096);
        if (is_file($dest)) {
            @chmod($dest, 0600);
        }
    }

    /** @param array<int,string> $command */
    private function runProcess(array $command, int $maxOutput = self::MAX_PROCESS_OUTPUT): string
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0.0) {
            return '';
        }
        $timeout = min(self::PROCESS_TIMEOUT_SECONDS, $remaining);
        $pipes = [];
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return '';
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $started = microtime(true);
        $timedOut = false;
        do {
            // Bound each read as well as the retained output. Without a per-read
            // cap, a noisy child could cause an oversized transient allocation.
            $stdout = stream_get_contents($pipes[1], 8192);
            if (is_string($stdout) && strlen($output) < $maxOutput) {
                $output .= substr($stdout, 0, $maxOutput - strlen($output));
            }
            // Drain stderr so a noisy child cannot block, but never return it.
            stream_get_contents($pipes[2], 8192);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ((microtime(true) - $started) >= $timeout || microtime(true) >= $this->deadline) {
                $timedOut = true;
                proc_terminate($process);
                usleep(100_000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                break;
            }
            usleep(20_000);
        } while (true);

        // Once the child has stopped, drain any buffered output in bounded
        // chunks. Retain at most maxOutput bytes and discard stderr.
        do {
            $stdout = stream_get_contents($pipes[1], 8192);
            if (is_string($stdout) && strlen($output) < $maxOutput) {
                $output .= substr($stdout, 0, $maxOutput - strlen($output));
            }
        } while (is_string($stdout) && $stdout !== '');
        do {
            $stderr = stream_get_contents($pipes[2], 8192);
        } while (is_string($stderr) && $stderr !== '');
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $timedOut ? '' : $this->boundedUtf8(trim($output), $maxOutput);
    }

    private function temporaryPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new \RuntimeException('Unable to create secure temporary file.');
        }
        @chmod($path, 0600);
        return $path;
    }

    private function boundedUtf8(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        return function_exists('mb_strcut') ? mb_strcut($value, 0, $maxBytes, 'UTF-8') : substr($value, 0, $maxBytes);
    }
}
