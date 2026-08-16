<?php

declare(strict_types=1);

namespace Velora\Trades;

use Velora\Core\Exceptions\NotFoundException;
use Velora\Core\Exceptions\ValidationException;
use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Core\Validation;

/**
 * خروجی‌های جزئی معامله (TP / SL / manual / partial) — v0.1 trade_exits
 */
final class TradeExitController
{
    private const EXIT_TYPES = ['tp', 'sl', 'manual', 'partial'];

    private function repo(): TradeExitRepository
    {
        return new TradeExitRepository();
    }

    /** GET /api/v1/trades/{id}/exits */
    public function index(Request $request, array $params): never
    {
        $userId = (int) $request->attributes['user_id'];
        $tradeId = (int) $params['id'];

        $exits = $this->repo()->listByTrade($tradeId, $userId);
        Response::json(['items' => array_map([$this, 'serialize'], $exits)]);
    }

    /** POST /api/v1/trades/{id}/exits */
    public function store(Request $request, array $params): never
    {
        $userId = (int) $request->attributes['user_id'];
        $tradeId = (int) $params['id'];

        Validation::assert($request->body, [
            'exitType' => 'required|string',
            'exitPrice' => 'required|numeric',
            'volume' => 'required|numeric',
            'exitedAt' => 'required|string|datetime',
            'notes' => 'string|max:255',
        ]);

        $type = (string) $request->body['exitType'];
        if (!in_array($type, self::EXIT_TYPES, true)) {
            throw new ValidationException('Invalid exit type.', [
                'exitType' => [
                    'code' => 'INVALID_CHOICE',
                    'messageKey' => 'errors.validation.choice',
                    'params' => [],
                ],
            ]);
        }

        $exitPrice = self::decimal($request->body['exitPrice'], 'exitPrice', 10, 8);
        $volume = self::decimal($request->body['volume'], 'volume', 10, 8);
        if (bccomp($exitPrice, '0', 8) <= 0 || bccomp($volume, '0', 8) <= 0) {
            throw new ValidationException('Exit price and volume must be positive.', [
                'volume' => ['code' => 'MUST_BE_POSITIVE', 'messageKey' => 'errors.validation.positive', 'params' => []],
            ]);
        }
        $exitTimestamp = strtotime($request->body['exitedAt']);
        if ($exitTimestamp === false) {
            throw new ValidationException('Invalid exit time.', ['exitedAt' => ['code' => 'INVALID_DATETIME', 'messageKey' => 'errors.validation.datetime', 'params' => []]]);
        }

        $id = $this->repo()->create($tradeId, $userId, [
            'exitType' => $type,
            'exitPrice' => $exitPrice,
            'volume' => $volume,
            // Client-provided PnL is intentionally ignored; the repository
            // computes it from the locked parent trade.
            'exitedAt' => gmdate('Y-m-d H:i:s', $exitTimestamp),
            'notes' => isset($request->body['notes']) && $request->body['notes'] !== ''
                ? $request->body['notes']
                : null,
        ]);

        Response::json([
            'id' => $id,
            'messageKey' => 'trades.exitCreated',
            'params' => (object) [],
        ], 201);
    }

    /** DELETE /api/v1/trades/exits/{exitId} */
    public function destroy(Request $request, array $params): never
    {
        $userId = (int) $request->attributes['user_id'];
        $exitId = (int) $params['exitId'];

        $deleted = $this->repo()->delete($exitId, $userId);
        if (!$deleted) {
            throw new NotFoundException('Trade exit not found.', 'TRADE_EXIT_NOT_FOUND', 'errors.trades.exitNotFound');
        }
        Response::json(['deleted' => true]);
    }

    private static function decimal(mixed $value, string $field, int $maxIntegerDigits, int $maxFractionDigits): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new ValidationException('Invalid numeric amount.', [$field => ['code' => 'NOT_NUMERIC', 'messageKey' => 'errors.validation.numeric', 'params' => []]]);
        }
        $value = trim((string) $value);
        $pattern = '/\A\d{1,' . $maxIntegerDigits . '}(?:\.\d{1,' . $maxFractionDigits . '})?\z/D';
        if (!preg_match($pattern, $value)) {
            throw new ValidationException('Invalid numeric amount.', [$field => ['code' => 'INVALID_DECIMAL', 'messageKey' => 'errors.validation.decimal', 'params' => ['maxIntegerDigits' => $maxIntegerDigits, 'maxFractionDigits' => $maxFractionDigits]]]);
        }
        return bcadd($value, '0', 8);
    }

    private function serialize(array $e): array
    {
        return [
            'id' => (int) $e['id'],
            'tradeId' => (int) $e['trade_id'],
            'exitType' => $e['exit_type'],
            'exitPrice' => $e['exit_price'],
            'volume' => $e['volume'],
            'pnl' => $e['pnl'],
            'exitedAt' => $e['exited_at'],
            'notes' => $e['notes'],
        ];
    }
}
