<?php

declare(strict_types=1);

namespace Velora\AI\Extraction;

/**
 * DTO for extracted trade data from screenshot.
 * Follows existing Velora trade fields.
 */
final class ExtractedTradeData
{
    public function __construct(
        public readonly ?string $symbol = null,
        public readonly ?string $side = null, // buy/sell
        public readonly ?string $entry = null,
        public readonly ?string $exit = null,
        public readonly ?string $lot = null,
        public readonly ?string $sl = null,
        public readonly ?string $tp = null,
        public readonly ?string $pnl = null,
        public readonly ?string $openTime = null,
        public readonly ?string $closeTime = null,
        public readonly float $confidence = 0.0,
        public readonly string $provider = 'unknown',
        public readonly ?string $rawText = null,
        public readonly array $rawResponse = [],
        // --- Phase 2D: SOURCE datetime evidence (verbatim; never normalized here) ---
        public readonly ?string $rawOpenText = null,
        public readonly ?string $rawCloseText = null,
        public readonly ?string $sourceCalendar = null,   // gregorian|jalali|unknown
        public readonly ?string $dateFormat = null,       // DD/MM/YYYY|MM/DD/YYYY|YYYY/MM/DD|YYYY-MM-DD|unknown
        public readonly ?string $timezoneText = null,     // verbatim visible tz/offset label
        public readonly ?int $timezoneOffsetHintMinutes = null, // only if an explicit offset is shown
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'side' => $this->side,
            'entry' => $this->entry,
            'exit' => $this->exit,
            'lot' => $this->lot,
            'sl' => $this->sl,
            'tp' => $this->tp,
            'pnl' => $this->pnl,
            'openTime' => $this->openTime,
            'closeTime' => $this->closeTime,
            'confidence' => $this->confidence,
            'provider' => $this->provider,
            'rawText' => $this->rawText,
            // Phase 2D datetime evidence (null unless the model/contract provides it).
            'rawOpenText' => $this->rawOpenText,
            'rawCloseText' => $this->rawCloseText,
            'sourceCalendar' => $this->sourceCalendar,
            'dateFormat' => $this->dateFormat,
            'timezoneText' => $this->timezoneText,
            'timezoneOffsetHintMinutes' => $this->timezoneOffsetHintMinutes,
        ];
    }

    /**
     * Create from AI JSON response.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data, string $provider = 'unknown', float $confidence = 0.0, ?string $rawText = null, array $rawResponse = []): self
    {
        // Normalize side
        $side = strtolower((string) ($data['side'] ?? $data['direction'] ?? ''));
        if (!in_array($side, ['buy', 'sell'], true)) {
            $side = null;
        }

        return new self(
            symbol: isset($data['symbol']) ? strtoupper(trim((string) $data['symbol'])) : null,
            side: $side,
            entry: isset($data['entry']) ? (string) $data['entry'] : (isset($data['entry_price']) ? (string) $data['entry_price'] : null),
            exit: isset($data['exit']) ? (string) $data['exit'] : (isset($data['exit_price']) ? (string) $data['exit_price'] : null),
            lot: isset($data['lot']) ? (string) $data['lot'] : (isset($data['volume']) ? (string) $data['volume'] : null),
            sl: isset($data['sl']) ? (string) $data['sl'] : (isset($data['stop_loss']) ? (string) $data['stop_loss'] : null),
            tp: isset($data['tp']) ? (string) $data['tp'] : (isset($data['take_profit']) ? (string) $data['take_profit'] : null),
            pnl: isset($data['pnl']) ? (string) $data['pnl'] : (isset($data['profit']) ? (string) $data['profit'] : (isset($data['profit_loss']) ? (string) $data['profit_loss'] : null)),
            openTime: isset($data['openTime']) ? (string) $data['openTime'] : (isset($data['open_time']) ? (string) $data['open_time'] : null),
            closeTime: isset($data['closeTime']) ? (string) $data['closeTime'] : (isset($data['close_time']) ? (string) $data['close_time'] : null),
            confidence: $confidence,
            provider: $provider,
            rawText: $rawText,
            rawResponse: $rawResponse,
            // Phase 2D: verbatim/evidence passthrough — NO conversion here.
            rawOpenText: self::evidenceText($data['rawOpenText'] ?? $data['raw_open_text'] ?? null),
            rawCloseText: self::evidenceText($data['rawCloseText'] ?? $data['raw_close_text'] ?? null),
            sourceCalendar: self::calendarEvidence($data['sourceCalendar'] ?? $data['source_calendar'] ?? null),
            dateFormat: self::formatEvidence($data['dateFormat'] ?? $data['date_format'] ?? null),
            timezoneText: self::evidenceText($data['timezoneText'] ?? $data['timezone_text'] ?? null),
            timezoneOffsetHintMinutes: self::offsetEvidence($data['timezoneOffsetHintMinutes'] ?? $data['timezone_offset_hint_minutes'] ?? null),
        );
    }

    /**
     * For backward compat with existing ScreenshotExtractController response.
     */
    public function toLegacyArray(): array
    {
        return $this->toArray();
    }

    // --- Phase 2D evidence helpers: sanitize ONLY, never normalize/convert ---

    /**
     * Verbatim visible text. Kept exactly as observed (digits/separators
     * preserved); only length-capped and control characters trimmed. Returns
     * null for empty/non-string so missing evidence is explicit.
     */
    private static function evidenceText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
            $value = trim($value);
        }
        if ($value === '') {
            return null;
        }
        // Cap length without depending on mbstring (hosts may lack it).
        if (strlen($value) <= 256) {
            return $value;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 64, 'UTF-8');
        }
        // Byte-safe prefix: never cut a multibyte sequence in half.
        $prefix = substr($value, 0, 256);
        return preg_replace('/[\x80-\xBF]+$/', '', $prefix) ?? $prefix;
    }

    /**
     * Restrict to the known calendar vocabulary; anything else => unknown so a
     * model can never smuggle a guess (e.g. an IANA timezone) into this field.
     */
    private static function calendarEvidence(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return match (strtolower(trim($value))) {
            'gregorian' => 'gregorian',
            'jalali', 'persian', 'shamsi' => 'jalali',
            'unknown', '', 'null' => 'unknown',
            default => 'unknown',
        };
    }

    /**
     * Restrict to the known date-format vocabulary; ambiguous/unknown => unknown.
     */
    private static function formatEvidence(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $allowed = ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY/MM/DD', 'YYYY-MM-DD', 'unknown'];
        $v = strtoupper(trim($value));
        // Tolerate separators for the year-first forms.
        $v = str_replace(['.', '-'], ['/', '-'], $v);
        return in_array($v, $allowed, true) ? $v : 'unknown';
    }

    /**
     * Integer minutes only when an explicit numeric offset was observed.
     * Bounded to a sane range; non-numeric/empty => null. Never derived here.
     */
    private static function offsetEvidence(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }
        if (is_int($value)) {
            $minutes = $value;
        } elseif (is_string($value) && preg_match('/\A[+-]?\d{1,4}\z/', trim($value))) {
            $minutes = (int) $value;
        } elseif (is_numeric($value)) {
            $minutes = (int) $value;
        } else {
            return null;
        }
        // Valid UTC offsets span -12:00..+14:00 => -720..+840 minutes.
        return ($minutes >= -720 && $minutes <= 840) ? $minutes : null;
    }
}
