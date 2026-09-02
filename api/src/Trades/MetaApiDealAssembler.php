<?php

declare(strict_types=1);

namespace Velora\Trades;

/**
 * Phase 4 — assemble MetaApi HISTORY DEALS into closed-position trades.
 *
 * MetaApi history-deals are individual FILLS. A position is opened by one or
 * more DEAL_ENTRY_IN fills and closed by one or more DEAL_ENTRY_OUT fills, all
 * sharing a positionId. Velora's `trades` table models one CLOSED round-trip
 * (entry price/time + exit price/time). This assembler reconstructs that model
 * WITHOUT fabricating timestamps or prices:
 *
 *   - occurred_open_at_utc  = the open-side (DEAL_ENTRY_IN) absolute instant:
 *                             the EARLIEST IN fill (when the position began).
 *   - occurred_close_at_utc = the close-side (DEAL_ENTRY_OUT) absolute instant:
 *                             the LATEST OUT fill (when the position finished).
 *
 * Close = LATEST OUT (Phase 4A audit): a closed position fully closes on its
 * final exit. This is anchored by Velora's OWN shipped invariant — TradeService/
 * TradeRepository validate "trade times must contain every recorded exit",
 * i.e. close_time >= MAX(trade_exits.exited_at) (see TradeRepository::update).
 * Using the EARLIEST partial exit as close_time would place the trade's close
 * BEFORE later exits already recorded, violating that invariant. Open = the
 * earliest IN by the same boundary logic (MIN of the opening fills).
 *
 * Open and close are derived INDEPENDENTLY. A position that has no resolvable
 * open instant or no resolvable close instant is NOT emitted as a closed trade
 * (it is counted as skipped) — we never duplicate one deal's instant across
 * open and close, and never insert a closed trade with an unknown boundary.
 *
 * Only offset-explicit `time` instants are used for canonical columns (via
 * {@see MetaApiInstantResolver}). The naive `brokerTime` is NEVER used for an
 * instant (it is evidence only and has no column here).
 *
 * Fill-level idempotency: within one assembly, identical fills (same deal id)
 * are collapsed so a repeated deal never double-counts volume/PnL. Position
 * idempotency across batches is enforced by the (account_id, external_deal_id)
 * unique key at persistence time.
 *
 * Financials are aggregated deterministically:
 *   - direction  = direction of the opening (IN) fill (buy/sell).
 *   - volume     = total IN volume (position size opened).
 *   - entry/exit = volume-weighted average fill price across IN / OUT fills.
 *   - profit/commission/swap = sum across the position's fills.
 *
 * Pure service: no DB, no I/O, no PHP-default-timezone dependency.
 */
final class MetaApiDealAssembler
{
    public function __construct(
        private readonly MetaApiInstantResolver $instants = new MetaApiInstantResolver(),
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $deals normalized deals (as produced
     *        by MetaApiService::normalizeExternalDeal); each carries at minimum
     *        position_id, entry_type ('in'|'out'|null), direction, time_utc
     *        (canonical "Y-m-d H:i:s" or null), price, volume, profit, etc.
     * @return array{trades: array<int,array<string,mixed>>, skipped: array<int,array{key:string,reason:string}>}
     */
    public function assemble(array $deals): array
    {
        $groups = [];   // positionKey => ['in'=>[], 'out'=>[]]
        $keyless = [];
        $seenDeals = []; // dealId => true (fill-level dedup within this assembly)

        foreach ($deals as $deal) {
            if (!is_array($deal)) {
                continue;
            }
            $side = $this->entrySide($deal['entry_type'] ?? null);
            if ($side === null) {
                // Balance/credit/inout/other non-trade deal types are ignored:
                // they are neither opens nor closes and carry no position PnL.
                continue;
            }
            // Fill-level idempotency: collapse an identical repeated deal so it
            // can never double-count volume/PnL within one assembly.
            $dealId = (string) ($deal['external_deal_id'] ?? '');
            if ($dealId !== '') {
                if (isset($seenDeals[$dealId])) {
                    continue;
                }
                $seenDeals[$dealId] = true;
            }
            $key = $this->groupKey($deal);
            if ($key === null) {
                // A real trade fill with no positionId cannot be paired.
                $keyless[] = $deal;
                continue;
            }
            $groups[$key][$side][] = $deal;
        }

        $trades = [];
        $skipped = [];

        // A lone fill that cannot be attributed to a position is never a closed
        // round-trip: record why and skip (no open=close fabrication).
        foreach ($keyless as $deal) {
            $skipped[] = [
                'key' => (string) ($deal['external_deal_id'] ?? 'deal'),
                'reason' => 'unpaired_deal_no_position_id',
            ];
        }

        foreach ($groups as $key => $sides) {
            $ins = $sides['in'] ?? [];
            $outs = $sides['out'] ?? [];

            if ($ins === [] || $outs === []) {
                // Open position or half-pair: cannot represent a CLOSED trade
                // truthfully. Skip; do not fabricate the missing boundary.
                $skipped[] = [
                    'key' => $key,
                    'reason' => $ins === [] ? 'missing_open_fill' : 'position_still_open',
                ];
                continue;
            }

            // A CLOSED round-trip requires the exit volume to cover the opened
            // volume. If OUT volume < IN volume the position is still partly
            // open: never fabricate a closed trade (no fake close boundary).
            $inVol = $this->sumDecimal($ins, 'volume');
            $outVol = $this->sumDecimal($outs, 'volume');
            if ($inVol === null || $outVol === null || bccomp($outVol, $inVol, 8) < 0) {
                $skipped[] = ['key' => $key, 'reason' => 'position_partially_open'];
                continue;
            }

            $openInstant = $this->boundaryInstant($ins, false);  // earliest IN
            $closeInstant = $this->boundaryInstant($outs, true);  // LATEST OUT

            if ($openInstant === null) {
                $skipped[] = ['key' => $key, 'reason' => 'open_instant_unresolved'];
                continue;
            }
            if ($closeInstant === null) {
                $skipped[] = ['key' => $key, 'reason' => 'close_instant_unresolved'];
                continue;
            }
            if ($closeInstant < $openInstant) {
                $skipped[] = ['key' => $key, 'reason' => 'close_before_open'];
                continue;
            }

            $direction = $this->openDirection($ins);
            if ($direction === null) {
                $skipped[] = ['key' => $key, 'reason' => 'unknown_direction'];
                continue;
            }

            $entry = $this->weightedAverage($ins, 'price');
            $exit = $this->weightedAverage($outs, 'price');
            $volume = $this->sumDecimal($ins, 'volume');
            $profit = $this->sumDecimal(array_merge($ins, $outs), 'profit');
            $commission = $this->sumDecimal(array_merge($ins, $outs), 'commission');
            $swap = $this->sumDecimal(array_merge($ins, $outs), 'swap');

            if ($entry === null || $exit === null || $volume === null
                || !$this->isPositive($entry) || !$this->isPositive($exit) || !$this->isPositive($volume)) {
                $skipped[] = ['key' => $key, 'reason' => 'invalid_prices_or_volume'];
                continue;
            }

            $symbol = $this->firstString($ins, 'symbol') ?? $this->firstString($outs, 'symbol');
            if ($symbol === null) {
                $skipped[] = ['key' => $key, 'reason' => 'missing_symbol'];
                continue;
            }

            $trades[] = [
                'external_deal_id' => $key,
                'position_id' => $key,
                'symbol' => $symbol,
                'direction' => $direction,
                'entry_price' => $entry,
                'exit_price' => $exit,
                'volume' => $volume,
                'profit_loss' => $profit ?? '0',
                'commission' => $commission ?? '0',
                'swap' => $swap ?? '0',
                // Absolute instants (canonical). Also satisfy legacy NOT NULL
                // open_time/close_time with the SAME true UTC instant — for
                // MetaApi rows open_time/close_time ARE genuine UTC (the
                // offset-explicit deal time), never a reinterpreted wall clock.
                'open_time' => $openInstant,
                'close_time' => $closeInstant,
                'occurred_open_at_utc' => $openInstant,
                'occurred_close_at_utc' => $closeInstant,
                'time_status' => 'resolved',
                'source_timezone' => null,
                'source_timezone_source' => MetaApiInstantResolver::PROVENANCE,
                'source_calendar' => 'gregorian',
                'raw_open_text' => $this->boundaryFill($ins, false)['time_raw'] ?? null,
                'raw_close_text' => $this->boundaryFill($outs, true)['time_raw'] ?? null,
            ];
        }

        return ['trades' => $trades, 'skipped' => $skipped];
    }

    /** Group key: positionId when present; else null (cannot pair). */
    private function groupKey(array $deal): ?string
    {
        $pid = $deal['position_id'] ?? null;
        if (is_string($pid) || is_int($pid)) {
            $pid = trim((string) $pid);
            if ($pid !== '' && preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/D', $pid) === 1) {
                return 'pos-' . $pid;
            }
        }
        return null;
    }

    /** Normalize MetaApi entryType (string or MT5 numeric) to a side. */
    private function entrySide(mixed $entryType): ?string
    {
        if (is_int($entryType)) {
            // MT5 DEAL_ENTRY_IN=0, DEAL_ENTRY_OUT=1 (INOUT=2, OUT_BY=3).
            return match ($entryType) {
                0 => 'in',
                1 => 'out',
                default => null,
            };
        }
        if (!is_string($entryType)) {
            return null;
        }
        $e = strtolower(trim($entryType));
        if ($e === '0' || $e === '1') {
            return $e === '0' ? 'in' : 'out';
        }
        return match ($e) {
            'in', 'deal_entry_in', 'entry_in' => 'in',
            'out', 'deal_entry_out', 'entry_out' => 'out',
            // deal_entry_inout / balance / credit are not trade fills.
            default => null,
        };
    }

    /**
     * Boundary canonical instant over a side's fills.
     * $latest=false => earliest (open = first IN); $latest=true => LATEST
     * (close = final OUT). Only resolved instants count.
     */
    private function boundaryInstant(array $fills, bool $latest): ?string
    {
        return $this->boundaryFill($fills, $latest)['time_utc'] ?? null;
    }

    /** The fill carrying the boundary instant (earliest or latest by time_utc). */
    private function boundaryFill(array $fills, bool $latest): ?array
    {
        $best = null;
        foreach ($fills as $f) {
            $instant = $f['time_utc'] ?? null;
            if (!is_string($instant) || $instant === '') {
                continue;
            }
            if ($best === null
                || ($latest && $instant > $best['time_utc'])
                || (!$latest && $instant < $best['time_utc'])) {
                $best = $f;
            }
        }
        return $best;
    }

    private function openDirection(array $ins): ?string
    {
        foreach ($ins as $f) {
            $d = $f['direction'] ?? null;
            if ($d === 'buy' || $d === 'sell') {
                return $d;
            }
        }
        return null;
    }

    /** Volume-weighted average of a decimal price field. */
    private function weightedAverage(array $fills, string $field): ?string
    {
        $num = '0';
        $den = '0';
        foreach ($fills as $f) {
            $price = $f[$field] ?? null;
            $vol = $f['volume'] ?? null;
            if (!is_string($price) || !is_string($vol) || $vol === '0') {
                continue;
            }
            $num = bcadd($num, bcmul($price, $vol, 8), 8);
            $den = bcadd($den, $vol, 8);
        }
        if ($den === '0') {
            return null;
        }
        return bcdiv($num, $den, 8);
    }

    private function sumDecimal(array $fills, string $field): ?string
    {
        $sum = '0';
        $seen = false;
        foreach ($fills as $f) {
            $v = $f[$field] ?? null;
            if (is_string($v) && $v !== '') {
                $sum = bcadd($sum, $v, 8);
                $seen = true;
            }
        }
        return $seen ? $sum : null;
    }

    private function isPositive(string $decimal): bool
    {
        return bccomp($decimal, '0', 8) > 0;
    }

    private function firstString(array $fills, string $field): ?string
    {
        foreach ($fills as $f) {
            $v = $f[$field] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return $v;
            }
        }
        return null;
    }
}
