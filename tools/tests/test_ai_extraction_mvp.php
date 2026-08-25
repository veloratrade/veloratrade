<?php

declare(strict_types=1);

/**
 * MVP AI Extraction tests — covers Gemini success, quota, timeout, malformed, fallback, validation.
 * Dependency-free, no DB, no network.
 */

// Mock Config for AI module
namespace Velora\Core {
    final class Config {
        public static array $values = [];
        public static function env(string $key, string $default = ''): string {
            return self::$values[$key] ?? $default;
        }
        public static function get(string $key, mixed $default = null): mixed {
            return $default;
        }
        public static function privatePath(string $path): string { return sys_get_temp_dir() . '/' . $path; }
        public static function isDevelopmentEnvironment(): bool { return false; }
    }
    final class Database {
        public static function connection(): \PDO {
            throw new \RuntimeException('DB not needed for this test');
        }
    }
}

namespace {
    // Load AI module files
    $base = dirname(__DIR__, 2) . '/api/src/AI';
    require $base . '/Exceptions/AIException.php';
    require $base . '/Exceptions/AIProviderException.php';
    require $base . '/Exceptions/AIQuotaExhaustedException.php';
    require $base . '/Exceptions/AITimeoutException.php';
    require $base . '/Exceptions/AIValidationException.php';
    require $base . '/Extraction/ExtractedTradeData.php';
    require $base . '/Extraction/ExtractionValidator.php';
    require $base . '/Providers/AIProviderInterface.php';
    require $base . '/Services/AIManager.php';
    require $base . '/Extraction/ScreenshotExtractor.php';

    // Mock providers
    use Velora\AI\Extraction\ExtractedTradeData;
    use Velora\AI\Exceptions\AIQuotaExhaustedException;
    use Velora\AI\Exceptions\AITimeoutException;
    use Velora\AI\Exceptions\AIValidationException;
    use Velora\AI\Exceptions\AIProviderException;
    use Velora\AI\Providers\AIProviderInterface;

    final class MockGeminiSuccess implements AIProviderInterface {
        public function getName(): string { return 'gemini'; }
        public function getCapabilities(): array { return ['vision']; }
        public function getCostTier(): int { return 0; }
        public function isAvailable(): bool { return true; }
        public function extract(string $imageRaw, float $deadline): ExtractedTradeData {
            return ExtractedTradeData::fromArray([
                'symbol' => 'XAUUSD',
                'side' => 'buy',
                'entry' => '2000.50',
                'exit' => '2010.20',
                'lot' => '0.1',
                'sl' => '1995',
                'tp' => '2020',
                'pnl' => '97.5',
                'openTime' => '2024-12-01T10:30',
                'closeTime' => '2024-12-01T12:45',
                'confidence' => 0.92,
            ], 'gemini', 0.92, '{"symbol":"XAUUSD"}');
        }
    }

    final class MockGeminiQuotaFail implements AIProviderInterface {
        public function getName(): string { return 'gemini'; }
        public function getCapabilities(): array { return ['vision']; }
        public function getCostTier(): int { return 0; }
        public function isAvailable(): bool { return true; }
        public function extract(string $imageRaw, float $deadline): ExtractedTradeData {
            throw new AIQuotaExhaustedException('quota exhausted', 'gemini');
        }
    }

    final class MockGeminiTimeout implements AIProviderInterface {
        public function getName(): string { return 'gemini'; }
        public function getCapabilities(): array { return ['vision']; }
        public function getCostTier(): int { return 0; }
        public function isAvailable(): bool { return true; }
        public function extract(string $imageRaw, float $deadline): ExtractedTradeData {
            throw new AITimeoutException('timeout', 'gemini');
        }
    }

    final class MockGeminiMalformed implements AIProviderInterface {
        public function getName(): string { return 'gemini'; }
        public function getCapabilities(): array { return ['vision']; }
        public function getCostTier(): int { return 0; }
        public function isAvailable(): bool { return true; }
        public function extract(string $imageRaw, float $deadline): ExtractedTradeData {
            throw new AIValidationException('malformed JSON', ['json' => ['code' => 'MALFORMED']]);
        }
    }

    final class MockTesseractFallback implements AIProviderInterface {
        public function getName(): string { return 'tesseract'; }
        public function getCapabilities(): array { return ['ocr']; }
        public function getCostTier(): int { return 0; }
        public function isAvailable(): bool { return true; }
        public function extract(string $imageRaw, float $deadline): ExtractedTradeData {
            return new ExtractedTradeData(
                symbol: 'EURUSD',
                side: 'sell',
                entry: '1.0850',
                exit: null,
                lot: '0.2',
                sl: null,
                tp: null,
                pnl: null,
                openTime: null,
                closeTime: null,
                confidence: 0.4,
                provider: 'tesseract',
                rawText: 'EURUSD SELL 1.0850',
            );
        }
    }

    final class MockTesseractUnavailable implements AIProviderInterface {
        public function getName(): string { return 'tesseract'; }
        public function getCapabilities(): array { return ['ocr']; }
        public function getCostTier(): int { return 0; }
        public function isAvailable(): bool { return false; }
        public function extract(string $imageRaw, float $deadline): ExtractedTradeData {
            throw new AIProviderException('not available', 'tesseract');
        }
    }

    $assertions = 0;
    $passed = 0;
    function expect(bool $cond, string $msg): void {
        global $assertions, $passed;
        $assertions++;
        if ($cond) {
            $passed++;
            echo "PASS: $msg\n";
        } else {
            fwrite(STDERR, "FAIL: $msg\n");
            exit(1);
        }
    }

    echo "=== AI Extraction MVP Tests ===\n";

    // Test 1: Gemini success
    {
        $manager = new \Velora\AI\Services\AIManager([new MockGeminiSuccess(), new MockTesseractFallback()]);
        $deadline = microtime(true) + 10;
        $result = $manager->extract('fake-image-bytes', $deadline);
        expect($result->symbol === 'XAUUSD', 'Gemini success returns XAUUSD');
        expect($result->provider === 'gemini', 'Provider is gemini');
        expect($result->confidence === 0.92, 'Confidence 0.92');
    }

    // Test 2: Gemini quota failure -> fallback to Tesseract
    {
        $manager = new \Velora\AI\Services\AIManager([new MockGeminiQuotaFail(), new MockTesseractFallback()]);
        $deadline = microtime(true) + 10;
        $result = $manager->extract('fake-image', $deadline);
        expect($result->provider === 'tesseract', 'Quota failure falls back to tesseract');
        expect($result->symbol === 'EURUSD', 'Fallback returns EURUSD');
    }

    // Test 3: Gemini timeout -> fallback
    {
        $manager = new \Velora\AI\Services\AIManager([new MockGeminiTimeout(), new MockTesseractFallback()]);
        $deadline = microtime(true) + 10;
        $result = $manager->extract('fake', $deadline);
        expect($result->provider === 'tesseract', 'Timeout falls back to tesseract');
    }

    // Test 4: Malformed AI response -> fallback
    {
        $manager = new \Velora\AI\Services\AIManager([new MockGeminiMalformed(), new MockTesseractFallback()]);
        $deadline = microtime(true) + 10;
        $result = $manager->extract('fake', $deadline);
        expect($result->provider === 'tesseract', 'Malformed response falls back to tesseract');
    }

    // Test 5: All providers fail -> exception
    {
        $manager = new \Velora\AI\Services\AIManager([new MockGeminiQuotaFail(), new MockTesseractUnavailable()]);
        $deadline = microtime(true) + 10;
        $threw = false;
        try {
            $manager->extract('fake', $deadline);
        } catch (\Velora\AI\Exceptions\AIException $e) {
            $threw = true;
            expect($e->errorCode() === 'AI_QUOTA_EXHAUSTED' || $e->errorCode() === 'AI_PROVIDER_ERROR', 'All fail throws AI exception');
        }
        expect($threw, 'All providers fail should throw');
    }

    // Test 6: Validation failures
    {
        $validData = new ExtractedTradeData(symbol: 'XAUUSD', side: 'buy', entry: '2000.5', confidence: 0.9, provider: 'gemini');
        $validated = \Velora\AI\Extraction\ExtractionValidator::validate($validData);
        expect($validated->symbol === 'XAUUSD', 'Valid data passes validation');

        $invalidSymbol = new ExtractedTradeData(symbol: '!!!', side: 'buy', confidence: 0.9, provider: 'gemini');
        $threw = false;
        try {
            \Velora\AI\Extraction\ExtractionValidator::validate($invalidSymbol);
        } catch (\Velora\AI\Exceptions\AIValidationException $e) {
            $threw = true;
        }
        expect($threw, 'Invalid symbol should throw validation exception');

        $invalidSide = new ExtractedTradeData(symbol: 'XAUUSD', side: 'invalid', confidence: 0.9, provider: 'gemini');
        $threw = false;
        try {
            \Velora\AI\Extraction\ExtractionValidator::validate($invalidSide);
        } catch (\Velora\AI\Exceptions\AIValidationException $e) {
            $threw = true;
        }
        expect($threw, 'Invalid side should throw validation exception');
    }

    // Test 7: ExtractedTradeData fromArray normalization
    {
        $data = ExtractedTradeData::fromArray([
            'symbol' => ' xauusd ',
            'direction' => 'BUY',
            'entry_price' => '2000.5',
            'volume' => '0.1',
        ], 'gemini', 0.85);
        expect($data->symbol === 'XAUUSD', 'Symbol normalized to uppercase');
        expect($data->side === 'buy', 'Side normalized to lowercase');
        expect($data->entry === '2000.5', 'Entry mapped from entry_price');
        expect($data->lot === '0.1', 'Lot mapped from volume');
    }

    // Test 8: ScreenshotExtractor with manager
    {
        $manager = new \Velora\AI\Services\AIManager([new MockGeminiSuccess()]);
        $extractor = new \Velora\AI\Extraction\ScreenshotExtractor($manager);
        $deadline = microtime(true) + 10;
        $result = $extractor->extractSingle('fake', $deadline);
        expect($result->symbol === 'XAUUSD', 'ScreenshotExtractor returns validated data');
    }

    // Test 9: Config env handling — API key must come from Config::env()
    {
        \Velora\Core\Config::$values = ['GEMINI_API_KEY' => 'test-key-123'];
        // We can't fully test GeminiProvider without cURL mock, but check isAvailable
        // Need to load GeminiProvider with mocked Config
        require dirname(__DIR__, 2) . '/api/src/AI/Providers/GeminiProvider.php';
        $provider = new \Velora\AI\Providers\GeminiProvider();
        expect($provider->isAvailable() === true, 'GeminiProvider isAvailable when key set');
        \Velora\Core\Config::$values = ['GEMINI_API_KEY' => ''];
        $provider2 = new \Velora\AI\Providers\GeminiProvider();
        expect($provider2->isAvailable() === false, 'GeminiProvider not available when key empty');
    }

    echo "\n=== Tests: $passed/$assertions PASS ===\n";
    echo "AI_EXTRACTION_MVP: PASS\n";
}
