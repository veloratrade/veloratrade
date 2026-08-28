<?php

declare(strict_types=1);

namespace Velora\Trades;

/**
 * Server-side trade ownership resolution for AI features.
 *
 * Lives OUTSIDE api/src/AI on purpose: the AI module must never query the
 * trades table directly (architecture constraint — see tools/tests/test_ai_p1_architecture.py).
 * AI services call this resolver, which delegates to TradeRepository and
 * enforces ownership per trade id.
 */
final class TradeResolver
{
    private TradeRepository $repository;

    public function __construct(?TradeRepository $repository = null)
    {
        $this->repository = $repository ?? new TradeRepository();
    }

    /**
     * Resolve trade ids for a user into sanitized trade payloads.
     *
     * Ownership is enforced per id via TradeRepository::findOwned(). Ids that do
     * not belong to the user (or do not exist) are silently skipped — another
     * user's data is never returned and never reaches the AI prompt.
     *
     * @param array<int|string> $tradeIds
     * @return array<int, array<string, mixed>>
     */
    public function resolveOwned(int $userId, array $tradeIds, int $max = 100): array
    {
        $ids = [];
        foreach ($tradeIds as $id) {
            if (is_int($id)) {
                $ids[] = $id;
            } elseif (is_string($id) && ctype_digit($id)) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique($ids));

        $result = [];
        foreach ($ids as $id) {
            if (count($result) >= $max) {
                break;
            }
            $row = $this->repository->findOwned($id, $userId);
            if ($row === null) {
                continue;
            }
            $result[] = $this->toTradePayload($row);
        }

        return $result;
    }

    /**
     * Map a trades row to the field names the AI analysis layer expects.
     * Only whitelisted columns are forwarded — notes/emotional_score/strategy
     * tags never reach the model prompt.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toTradePayload(array $row): array
    {
        return [
            'symbol'      => $row['symbol'] ?? null,
            'direction'   => $row['direction'] ?? null,
            'side'        => $row['direction'] ?? null,
            'entry_price' => $row['entry_price'] ?? null,
            'exit_price'  => $row['exit_price'] ?? null,
            'profit_loss' => $row['profit_loss'] ?? null,
            'pnl'         => $row['profit_loss'] ?? null,
            'r_multiple'  => $row['r_multiple'] ?? null,
            'volume'      => $row['volume'] ?? null,
            'open_time'   => $row['open_time'] ?? null,
            'close_time'  => $row['close_time'] ?? null,
        ];
    }
}
