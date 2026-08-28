<?php

declare(strict_types=1);

namespace Velora\AI\Providers;

use Velora\AI\DTOs\AIResponseDTO;
use Velora\AI\Extraction\ExtractedTradeData;
use Velora\AI\Exceptions\AIProviderException;
use Velora\AI\Exceptions\AITimeoutException;

/**
 * Tesseract fallback provider — adapter around existing OCR logic.
 * Does NOT rewrite OCR, wraps current implementation.
 */
final class TesseractProvider implements AIProviderInterface
{
    private const MAX_PROCESS_OUTPUT = 262_144;
    private const PROCESS_TIMEOUT_SECONDS = 8.0;
    private const MAX_IMAGE_BYTES = 8_388_608;

    public function getName(): string
    {
        return 'tesseract';
    }

    public function getCapabilities(): array
    {
        return ['ocr', 'text'];
    }

    public function getCostTier(): int
    {
        return 0;
    }

    public function isAvailable(): bool
    {
        return $this->tesseractBin() !== null && function_exists('proc_open');
    }

    /**
     * Generic generate — for future text analysis, reports, etc.
     * For Tesseract, returns OCR text as content.
     */
    public function generate(string $prompt, array $context = [], array $options = []): AIResponseDTO
    {
        $deadline = $options['deadline'] ?? (microtime(true) + 8);
        $imageRaw = $context['imageRaw'] ?? $context['image'] ?? '';
        if (!is_string($imageRaw) || $imageRaw === '') {
            // Text-only request — Tesseract cannot handle, return empty
            return new AIResponseDTO(
                content: '',
                provider: $this->getName(),
                model: 'tesseract',
                latencyMs: 0,
                tokensUsed: 0,
                confidence: 0.0,
                status: 'failed',
                errorCode: 'UNSUPPORTED_CAPABILITY',
            );
        }

        $start = microtime(true);
        try {
            $extracted = $this->extract($imageRaw, $deadline);
            $latency = (int) ((microtime(true) - $start) * 1000);
            return new AIResponseDTO(
                content: $extracted->rawText ?? '',
                provider: $this->getName(),
                model: 'tesseract',
                latencyMs: $latency,
                tokensUsed: 0,
                confidence: $extracted->confidence,
                status: 'success',
                rawResponse: $extracted->rawResponse,
            );
        } catch (\Throwable $e) {
            $latency = (int) ((microtime(true) - $start) * 1000);
            return new AIResponseDTO(
                content: '',
                provider: $this->getName(),
                model: 'tesseract',
                latencyMs: $latency,
                tokensUsed: 0,
                confidence: 0.0,
                status: 'failed',
                errorCode: 'OCR_FAILED',
            );
        }
    }

    public function extract(string $imageRaw, float $deadline): ExtractedTradeData
    {
        if (!$this->isAvailable()) {
            throw new AIProviderException('OCR engine not available.', $this->getName());
        }

        if (microtime(true) >= $deadline) {
            throw new AITimeoutException('Deadline exceeded before OCR.', $this->getName());
        }

        $bin = $this->tesseractBin();
        if ($bin === null) {
            throw new AIProviderException('Tesseract binary not found.', $this->getName());
        }

        $texts = $this->ocrOne($bin, $imageRaw, $deadline);
        $times = $this->readTimesFromFirstImage($imageRaw, $deadline);

        // Try to parse symbol/side from OCR text via simple heuristics
        $parsed = $this->parseFromOcrText($texts);

        return new ExtractedTradeData(
            symbol: $parsed['symbol'] ?? null,
            side: $parsed['side'] ?? null,
            entry: $parsed['entry'] ?? null,
            exit: $parsed['exit'] ?? null,
            lot: $parsed['lot'] ?? null,
            sl: $parsed['sl'] ?? null,
            tp: $parsed['tp'] ?? null,
            pnl: $parsed['pnl'] ?? null,
            openTime: $times['openTime'] ?? null,
            closeTime: $times['closeTime'] ?? null,
            confidence: 0.4, // low confidence for OCR
            provider: $this->getName(),
            rawText: $texts,
            rawResponse: ['texts' => [$texts], 'times' => $times],
        );
    }

    /**
     * Simple heuristic parsing from OCR text — MVP only.
     * Future: improve with regex or ML.
     *
     * @return array<string,string|null>
     */
    private function parseFromOcrText(string $text): array
    {
        $result = [
            'symbol' => null,
            'side' => null,
            'entry' => null,
            'exit' => null,
            'lot' => null,
            'sl' => null,
            'tp' => null,
            'pnl' => null,
        ];

        $upper = strtoupper($text);

        // Symbol: look for common patterns like XAUUSD, EURUSD, BTCUSD, etc.
        if (preg_match('/\b([A-Z]{3,6}USD|[A-Z]{6,10}|BTC[A-Z]{3}|ETH[A-Z]{3})\b/', $upper, $m)) {
            $result['symbol'] = $m[1];
        }

        // Side: buy/sell
        if (preg_match('/\b(BUY|SELL)\b/i', $text, $m)) {
            $result['side'] = strtolower($m[1]);
        } elseif (strpos($upper, 'BUY') !== false) {
            $result['side'] = 'buy';
        } elseif (strpos($upper, 'SELL') !== false) {
            $result['side'] = 'sell';
        }

        // Try to find numbers near keywords
        // Entry: look for "Entry" or "Open" + number
        if (preg_match('/(?:ENTRY|OPEN|PRICE)[^\d]*([0-9]+\.[0-9]+)/i', $text, $m)) {
            $result['entry'] = $m[1];
        }

        // Exit
        if (preg_match('/(?:EXIT|CLOSE)[^\d]*([0-9]+\.[0-9]+)/i', $text, $m)) {
            $result['exit'] = $m[1];
        }

        // Lot / Volume
        if (preg_match('/(?:LOT|VOLUME|SIZE)[^\d]*([0-9]+\.[0-9]+)/i', $text, $m)) {
            $result['lot'] = $m[1];
        }

        // PnL
        if (preg_match('/(?:PROFIT|P\/L|PNL)[^\d\-]*([\-]?[0-9]+\.[0-9]+)/i', $text, $m)) {
            $result['pnl'] = $m[1];
        }

        return $result;
    }

    // --- Below is wrapped existing OCR logic from ScreenshotExtractController ---

    private function tesseractBin(): ?string
    {
        foreach (['/usr/bin/tesseract', '/usr/local/bin/tesseract', '/bin/tesseract'] as $path) {
            // Capability probe only: hosts whose open_basedir excludes /usr/bin raise an engine
            // warning here, and one printed warning corrupts the JSON contract of the whole
            // response (and leaks server paths). Suppressed; a blocked stat is simply "not usable".
            if (@\is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    private function ocrOne(string $bin, string $raw, float $deadline): string
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

            $hasFas = @\is_file('/usr/share/tesseract-ocr/5/tessdata/fas.traineddata')
                || @\is_file('/usr/share/tesseract-ocr/4.00/tessdata/fas.traineddata')
                || @\is_file('/usr/share/tessdata/fas.traineddata');
            $eng = $this->runTess($bin, $input, 'eng', $deadline);
            $fas = $hasFas ? $this->runTess($bin, $input, 'fas+eng', $deadline) : '';
            $dates = $this->ocrDateBands($bin, $input, $hasFas, $deadline);
            return $this->boundedUtf8(trim($eng . "\n" . $fas . "\n" . $dates), self::MAX_PROCESS_OUTPUT);
        } finally {
            @unlink($src);
            @unlink($prep);
        }
    }

    private function ocrDateBands(string $bin, string $input, bool $hasFas, float $deadline): string
    {
        if (!function_exists('imagecreatefromstring') || microtime(true) >= $deadline) {
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
            return $this->runTessPsm($bin, $path, $hasFas ? 'fas+eng' : 'eng', 6, $deadline);
        } finally {
            @unlink($path);
        }
    }

    private function runTessPsm(string $bin, string $input, string $lang, int $psm, float $deadline): string
    {
        return $this->runProcess([
            $bin, $input, 'stdout', '-l', $lang, '--psm', (string) $psm, '--oem', '1',
        ], self::MAX_PROCESS_OUTPUT, $deadline);
    }

    private function runTess(string $bin, string $input, string $lang, float $deadline): string
    {
        return $this->runTessPsm($bin, $input, $lang, 6, $deadline);
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
        $convert = @\is_executable('/usr/bin/convert') ? '/usr/bin/convert' : null;
        if ($convert === null) {
            return;
        }
        $this->runProcess([
            $convert, $src, '-colorspace', 'Gray', '-resize', '1600x1600>',
            '-normalize', '-contrast-stretch', '2%x2%', $dest,
        ], 4096, microtime(true) + 5);
        if (is_file($dest)) {
            @chmod($dest, 0600);
        }
    }

    /** @param array<int,string> $command */
    private function runProcess(array $command, int $maxOutput, float $deadline): string
    {
        $remaining = $deadline - microtime(true);
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
            $stdout = stream_get_contents($pipes[1], 8192);
            if (is_string($stdout) && strlen($output) < $maxOutput) {
                $output .= substr($stdout, 0, $maxOutput - strlen($output));
            }
            stream_get_contents($pipes[2], 8192);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ((microtime(true) - $started) >= $timeout || microtime(true) >= $deadline) {
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

    private function readTimesFromFirstImage(string $raw, float $deadline): array
    {
        $empty = ['openTime' => '', 'closeTime' => ''];
        $script = $this->findWorkerScript();
        if ($script === null || microtime(true) >= $deadline) {
            return $empty;
        }

        $tmp = $this->temporaryPath('velora_time_');
        try {
            if (file_put_contents($tmp, $raw, LOCK_EX) === false) {
                return $empty;
            }
            @chmod($tmp, 0600);
            $json = $this->runProcess(['python3', $script, $tmp], 65_536, $deadline);
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

    /**
     * P0 fix: reliable worker script path resolution.
     * Tries multiple candidate locations for cPanel / local dev / Docker.
     */
    private function findWorkerScript(): ?string
    {
        $candidates = [
            dirname(__DIR__, 2) . '/workers/read_mt5_times.py', // api/src/AI/Providers -> api/src -> api/src/../workers = api/workers
            dirname(__DIR__, 3) . '/workers/read_mt5_times.py', // api/src/AI/Providers -> api -> api/workers
            __DIR__ . '/../../workers/read_mt5_times.py', // explicit relative
            __DIR__ . '/../../../workers/read_mt5_times.py', // fallback
            dirname(__DIR__, 4) . '/api/workers/read_mt5_times.py', // project root/api/workers
        ];

        foreach ($candidates as $path) {
            // Normalize and check
            $real = realpath($path);
            if ($real !== false && is_file($real) && is_readable($real)) {
                return $real;
            }
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        // Last resort: try to locate via glob from api root
        $apiRoot = dirname(__DIR__, 3); // api/
        $found = glob($apiRoot . '/workers/read_mt5_times.py');
        if ($found !== false && isset($found[0]) && is_file($found[0])) {
            return $found[0];
        }

        return null;
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
