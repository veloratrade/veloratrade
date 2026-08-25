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
        );
    }

    /**
     * For backward compat with existing ScreenshotExtractController response.
     */
    public function toLegacyArray(): array
    {
        return $this->toArray();
    }
}
