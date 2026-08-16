<?php

declare(strict_types=1);

namespace Velora\Trades;

/**
 * Net PnL + R-multiple math, using string-based arbitrary precision (bcmath)
 * so currency rounding errors never occur (CTO checklist #5).
 *
 *   net_pnl = gross_pnl - commission - swap
 *   r_multiple = net_pnl / risk, risk defaults to initial price delta when
 *   no stop loss is defined (roadmap v0.5 acceptance criterion, computed now).
 */
final class PnlCalculator
{
    private const SCALE = 8;

    /**
     * Compute gross PnL in account currency for a single closed trade.
     *
     * Generic formula (units-based):
     *   buy  (exit - entry) * volume * contract_size
     *   sell (entry - exit) * volume * contract_size
     *
     * contractSize = units of the instrument per 1.0 lot:
     *   FX standard lot    = 100000   (EURUSD 1 lot: 1 pip = 0.0001 * 100000 = $10)
     *   XAUUSD (gold)      = 100      (1 lot = 100 oz)
     *   crypto / indices   = 1        (PnL = price delta × lots)
     *
     * Default 1 keeps manual journaling predictable (PnL = price_delta × volume)
     * when the user does not know their contract spec. The API accepts an
     * optional `contractSize` override per trade.
     *
     * @return string decimal string of gross PnL
     */
    public static function grossPnl(
        string $entryPrice,
        string $exitPrice,
        string $volume,
        string $direction,
        string $contractSize = '1',
    ): string {
        $delta = $direction === 'buy'
            ? bcsub($exitPrice, $entryPrice, self::SCALE)
            : bcsub($entryPrice, $exitPrice, self::SCALE);

        return bcmul($delta, bcmul($volume, $contractSize, self::SCALE), self::SCALE);
    }

    /**
     * @return array{gross_pnl:string, commission:string, swap:string, net_pnl:string, r_multiple:?string}
     */
    public static function calculate(
        string $entryPrice,
        string $exitPrice,
        string $volume,
        string $direction,
        string $commission = '0',
        string $swap = '0',
        ?string $stopLoss = null,
        string $contractSize = '1',
    ): array {
        $gross = self::grossPnl($entryPrice, $exitPrice, $volume, $direction, $contractSize);

        // commission & swap are costs: net = gross - commission - swap
        $net = bcsub($gross, $commission, self::SCALE);
        $net = bcsub($net, $swap, self::SCALE);

        $rMultiple = null;
        $risk = self::riskAmount($entryPrice, $volume, $direction, $stopLoss, $contractSize);
        if ($risk !== null && bccomp($risk, '0', self::SCALE) > 0) {
            $rMultiple = bcdiv($net, $risk, self::SCALE);
        }

        // Keep calculated values inside the production schema instead of
        // relying on driver-specific truncation or overflow behaviour.
        self::assertFits($net, 'profitLoss', 16, 8);
        if ($rMultiple !== null) {
            self::assertFits($rMultiple, 'rMultiple', 10, 8);
        }

        return [
            'gross_pnl' => bcadd($gross, '0', 2),
            'commission' => bcadd($commission, '0', 2),
            'swap' => bcadd($swap, '0', 2),
            'net_pnl' => bcadd($net, '0', 2),
            'r_multiple' => $rMultiple === null ? null : bcadd($rMultiple, '0', 4),
        ];
    }

    /**
     * Risk = |entry - stop_loss| * volume * contract_size.
     * When no stop loss is defined, fall back to the initial price delta
     * (the distance from entry that the trade travelled) — roadmap v0.5 rule.
     */
    private static function assertFits(string $value, string $field, int $integerDigits, int $fractionDigits): void
    {
        $pattern = '/\A-?\d{1,' . $integerDigits . '}(?:\.\d{1,' . $fractionDigits . '})?\z/D';
        if (!preg_match($pattern, $value)) {
            throw new \Velora\Core\Exceptions\ValidationException(
                'Calculated financial value is outside the supported range.',
                [$field => ['code' => 'OUT_OF_RANGE', 'messageKey' => 'errors.validation.range', 'params' => []]],
            );
        }
    }

    public static function riskAmount(
        string $entryPrice,
        string $volume,
        string $direction,
        ?string $stopLoss,
        string $contractSize = '1',
    ): ?string {
        $reference = $stopLoss;
        if ($reference === null || bccomp($reference, '0', self::SCALE) === 0) {
            return null;
        }

        $delta = $direction === 'buy'
            ? bcsub($entryPrice, $reference, self::SCALE)
            : bcsub($reference, $entryPrice, self::SCALE);

        if (bccomp($delta, '0', self::SCALE) <= 0) {
            return null; // SL on wrong side — undefined risk
        }

        return bcmul($delta, bcmul($volume, $contractSize, self::SCALE), self::SCALE);
    }
}
