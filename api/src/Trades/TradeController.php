<?php

declare(strict_types=1);

namespace Velora\Trades;

use Velora\Core\Config;
use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Core\Validation;

/**
 * HTTP layer for /api/v1/trades (GET/POST/PUT/DELETE).
 */
final class TradeController
{
    public function __construct(
        private readonly TradeService $service = new TradeService(),
    ) {
    }

    public function index(Request $request): never
    {
        $userId = (int) $request->attributes['user_id'];

        $limit = max(1, min(
            (int) Config::get('pagination_max_limit', 200),
            (int) ($request->query['limit'] ?? Config::get('pagination_default_limit', 20))
        ));
        $page = max(1, (int) ($request->query['page'] ?? 1));

        $result = $this->service->repository()->search(
            [
                'user_id' => $userId,
                'symbol' => trim((string) ($request->query['symbol'] ?? '')),
                'direction' => trim((string) ($request->query['direction'] ?? '')),
                'from' => trim((string) ($request->query['from'] ?? '')),
                'to' => trim((string) ($request->query['to'] ?? '')),
                'q' => trim((string) ($request->query['q'] ?? '')),
            ],
            ['limit' => $limit, 'offset' => ($page - 1) * $limit, 'order' => (string) ($request->query['order'] ?? 'close_time')],
        );

        Response::json([
            'items' => array_map([$this->service, 'serialize'], $result['items']),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $result['total'],
                'totalPages' => max(1, (int) ceil($result['total'] / $limit)),
            ],
        ]);
    }

    public function show(Request $request, array $params): never
    {
        $userId = (int) $request->attributes['user_id'];
        $trade = $this->service->repository()->requireOwned((int) $params['id'], $userId);
        Response::json(['trade' => $this->service->serialize($trade)]);
    }

    public function store(Request $request): never
    {
        Validation::assert($request->body, [
            'symbol' => 'required|string|max:32',
            'direction' => 'required|in:buy,sell',
            'entryPrice' => 'required|numeric',
            'exitPrice' => 'required|numeric',
            'volume' => 'required|numeric',
            // اصلاح باگ: این فیلدها در buildTrade الزامی‌اند؛ بدون اعتبارسنجی،
            // خطای مبهم «فرمت تاریخ/زمان صحیح نیست» به کلاینت برمی‌گشت.
            'openTime' => 'required|datetime',
            'closeTime' => 'required|datetime',
        ]);

        $trade = $this->service->create($request->body, (int) $request->attributes['user_id']);
        Response::json(['trade' => $this->service->serialize($trade)], 201);
    }

    public function update(Request $request, array $params): never
    {
        $userId = (int) $request->attributes['user_id'];
        $trade = $this->service->update((int) $params['id'], $request->body, $userId);
        Response::json(['trade' => $this->service->serialize($trade)]);
    }

    public function destroy(Request $request, array $params): never
    {
        $userId = (int) $request->attributes['user_id'];
        $this->service->repository()->delete((int) $params['id'], $userId);
        Response::json(['deleted' => true]);
    }

    public function symbols(Request $request): never
    {
        $userId = (int) $request->attributes['user_id'];
        Response::json(['symbols' => $this->service->repository()->symbols($userId)]);
    }
}
