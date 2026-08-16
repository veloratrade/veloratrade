<?php

declare(strict_types=1);

namespace Velora\Trades;

use Velora\Core\Exceptions\ValidationException;

/**
 * Trade business logic: validate input, compute PnL/R-multiple via
 * PnlCalculator, delegate persistence to TradeRepository.
 * Always recompute financial fields server-side — never trust the client.
 */
final class TradeService
{
    private const DIRECTION = ['buy', 'sell'];
    private const SOURCE = ['manual', 'auto_sync'];

    public function __construct(
        private readonly TradeRepository $repository = new TradeRepository(),
    ) {
    }

    public function repository(): TradeRepository
    {
        return $this->repository;
    }

    /**
     * @param array<string,mixed> $raw validated-by-controller input
     * @return array<string,mixed> normalized trade ready for persistence
     */
    public function buildTrade(array $raw, int $userId): array
    {
        $rawSymbol = $raw['symbol'] ?? '';
        $symbol = is_string($rawSymbol) ? mb_strtoupper(trim($rawSymbol)) : '';
        $rawDirection = $raw['direction'] ?? 'buy';
        $direction = is_string($rawDirection) ? $rawDirection : '';
        // Manual trade API must not allow clients to impersonate auto-sync source.
        // MetaApi/import flows write source directly in their own service path.
        $source = 'manual';

        if ($symbol === '') {
            throw new ValidationException('Symbol is required.', ['symbol' => ['code' => 'REQUIRED', 'messageKey' => 'errors.validation.required', 'params' => []]]);
        }
        // Broker symbols are data, but constraining them to their portable ASCII
        // representation also prevents stored markup/attribute injection.
        if (!preg_match('/\A[A-Z0-9#][A-Z0-9._:\/#+\-]{0,31}\z/D', $symbol)) {
            throw new ValidationException('Invalid symbol.', ['symbol' => ['code' => 'INVALID_FORMAT', 'messageKey' => 'errors.validation.format', 'params' => []]]);
        }
        if (!in_array($direction, self::DIRECTION, true)) {
            throw new ValidationException('Invalid trade direction.', ['direction' => ['code' => 'INVALID_CHOICE', 'messageKey' => 'errors.validation.choice', 'params' => []]]);
        }
        if (!in_array($source, self::SOURCE, true)) {
            throw new ValidationException('Invalid trade source.', ['source' => ['code' => 'INVALID_CHOICE', 'messageKey' => 'errors.validation.choice', 'params' => []]]);
        }

        $accountId = null;
        if (isset($raw['accountId']) && $raw['accountId'] !== '') {
            $rawAccountId = $raw['accountId'];
            if ((!is_int($rawAccountId) && !is_string($rawAccountId))
                || !preg_match('/\A[1-9]\d*\z/D', (string) $rawAccountId)) {
                throw new ValidationException('Invalid broker account.', ['accountId' => ['code' => 'INVALID_FORMAT', 'messageKey' => 'errors.validation.format', 'params' => []]]);
            }
            $accountId = (int) $rawAccountId;
        }
        if ($accountId !== null && !$this->repository->verifyAccountOwnership($accountId, $userId)) {
            throw new ValidationException('Broker account ownership check failed.', ['accountId' => ['code' => 'ACCOUNT_NOT_OWNED', 'messageKey' => 'errors.trades.accountNotOwned', 'params' => []]]);
        }

        $entryPrice = self::dec($raw['entryPrice'] ?? null, 'entryPrice', 10, 8);
        $exitPrice = self::dec($raw['exitPrice'] ?? null, 'exitPrice', 10, 8);
        $volume = self::dec($raw['volume'] ?? null, 'volume', 10, 8);
        $commission = self::dec($raw['commission'] ?? '0', 'commission', 10, 8);
        $swap = self::dec($raw['swap'] ?? '0', 'swap', 10, 8);
        $stopLoss = isset($raw['stopLoss']) && $raw['stopLoss'] !== null && $raw['stopLoss'] !== ''
            ? self::dec($raw['stopLoss'], 'stopLoss', 10, 8) : null;
        $takeProfit = isset($raw['takeProfit']) && $raw['takeProfit'] !== null && $raw['takeProfit'] !== ''
            ? self::dec($raw['takeProfit'], 'takeProfit', 10, 8) : null;
        $contractSize = self::dec($raw['contractSize'] ?? '1', 'contractSize', 10, 8);

        if (bccomp($entryPrice, '0', 8) <= 0 || bccomp($exitPrice, '0', 8) <= 0) {
            throw new ValidationException('Trade prices must be positive.', ['entryPrice' => ['code' => 'MUST_BE_POSITIVE', 'messageKey' => 'errors.validation.positive', 'params' => []]]);
        }
        if (bccomp($volume, '0', 8) <= 0) {
            throw new ValidationException('Volume must be positive.', ['volume' => ['code' => 'MUST_BE_POSITIVE', 'messageKey' => 'errors.validation.positive', 'params' => []]]);
        }
        if (bccomp($contractSize, '0', 8) <= 0) {
            throw new ValidationException('Contract size must be positive.', ['contractSize' => ['code' => 'MUST_BE_POSITIVE', 'messageKey' => 'errors.validation.positive', 'params' => []]]);
        }
        if ($stopLoss !== null && bccomp($stopLoss, '0', 8) <= 0) {
            throw new ValidationException('Stop loss must be positive.', ['stopLoss' => ['code' => 'MUST_BE_POSITIVE', 'messageKey' => 'errors.validation.positive', 'params' => []]]);
        }
        if ($takeProfit !== null && bccomp($takeProfit, '0', 8) <= 0) {
            throw new ValidationException('Take profit must be positive.', ['takeProfit' => ['code' => 'MUST_BE_POSITIVE', 'messageKey' => 'errors.validation.positive', 'params' => []]]);
        }

        $openTime = self::toMysqlDatetime($raw['openTime'] ?? null);
        $closeTime = self::toMysqlDatetime($raw['closeTime'] ?? null);
        if ($closeTime < $openTime) {
            throw new ValidationException('Close time must not precede open time.', ['closeTime' => ['code' => 'INVALID_CHRONOLOGY', 'messageKey' => 'errors.validation.datetime', 'params' => []]]);
        }

        $strategyTag = self::optionalText($raw['strategyTag'] ?? null, 'strategyTag', 64);
        $notes = self::optionalText($raw['notes'] ?? null, 'notes', 5000);

        $emotionalScore = null;
        if (isset($raw['emotionalScore']) && $raw['emotionalScore'] !== '') {
            $rawScore = $raw['emotionalScore'];
            if ((!is_int($rawScore) && !is_string($rawScore)) || !preg_match('/\A[1-5]\z/D', (string) $rawScore)) {
                throw new ValidationException('Emotional score is out of range.', ['emotionalScore' => ['code' => 'OUT_OF_RANGE', 'messageKey' => 'errors.validation.range', 'params' => ['min' => 1, 'max' => 5]]]);
            }
            $emotionalScore = (int) $rawScore;
        }
        if ($emotionalScore !== null && ($emotionalScore < 1 || $emotionalScore > 5)) {
            throw new ValidationException('Emotional score is out of range.', ['emotionalScore' => ['code' => 'OUT_OF_RANGE', 'messageKey' => 'errors.validation.range', 'params' => ['min' => 1, 'max' => 5]]]);
        }

        $calc = PnlCalculator::calculate(
            $entryPrice,
            $exitPrice,
            $volume,
            $direction,
            $commission,
            $swap,
            $stopLoss,
            $contractSize,
        );

        return [
            'user_id' => $userId,
            'account_id' => $accountId,
            'symbol' => $symbol,
            'direction' => $direction,
            'entry_price' => $entryPrice,
            'exit_price' => $exitPrice,
            'volume' => $volume,
            'contract_size' => $contractSize,
            'commission' => $commission,
            'swap' => $swap,
            'profit_loss' => $calc['net_pnl'],
            'r_multiple' => $calc['r_multiple'],
            'stop_loss' => $stopLoss,
            'take_profit' => $takeProfit,
            'open_time' => $openTime,
            'close_time' => $closeTime,
            'strategy_tag' => $strategyTag,
            'emotional_score' => $emotionalScore,
            'notes' => $notes,
            'source' => $source,
        ];
    }

    public function create(array $raw, int $userId): array
    {
        $trade = $this->buildTrade($raw, $userId);
        $id = $this->repository->create($trade);
        $created = $this->repository->findOwned($id, $userId);
        if ($created === null) {
            throw new \RuntimeException('Failed to read back created trade.');
        }

        // بررسی اولین معامله و ارسال ایمیل + دستاورد جدید
        try {
            if ($this->repository->countTradesForUser($userId) === 1) {
                $user = (new \Velora\Auth\UserRepository())->findById($userId);
                if ($user !== null) {
                    $dashboardUrl = rtrim((string) \Velora\Core\Config::get('frontend_url', 'https://veloratrade.ir'), '/') . '/dashboard';
                    $profileUrl = rtrim((string) \Velora\Core\Config::get('frontend_url', 'https://veloratrade.ir'), '/') . '/profile';

                    \Velora\Core\NotificationService::sendFirstTradeEmail(
                        $user['email'],
                        $user['full_name'] ?: $user['email'],
                        $created['symbol'],
                        $created['direction'],
                        $dashboardUrl,
                        $userId
                    );

                    $achievementTitleKey = 'achievements.firstTrade.title';
                    $achievementDescriptionKey = 'achievements.firstTrade.description';
                    if ((new \Velora\Core\UserAchievementRepository())->unlock(
                        $userId,
                        'FIRST_TRADE',
                        $achievementTitleKey,
                        $achievementDescriptionKey,
                    )) {
                        \Velora\Core\NotificationService::sendAchievementUnlockedEmail(
                            $user['email'],
                            $user['full_name'] ?: $user['email'],
                            $achievementTitleKey,
                            $achievementDescriptionKey,
                            $profileUrl,
                            $userId,
                        );
                    }
                }
            }
        } catch (\Throwable) {
            // خطای احتمالی اعلان نباید مانع ثبت موفق معامله شود
        }

        return $created;
    }

    public function update(int $id, array $raw, int $userId): array
    {
        $existing = $this->repository->requireOwned($id, $userId);
        // اصلاح باگ: ردیف دیتابیس snake_case است ولی buildTrade انتظار camelCase دارد.
        // اگر کلاینت فقط بخشی از فیلدها را بفرستد (ویرایش جزئی)، array_merge قبلی
        // مقدار موجود (مثلاً entry_price) را به‌عنوان entryPrice نمی‌دید و
        // «مقدار عددی نامعتبر است» برمی‌گشت.
        $merged = array_merge(self::snakeToCamel($existing), $raw);
        $trade = $this->buildTrade($merged, $userId);
        $this->repository->update($id, $trade);
        $updated = $this->repository->findOwned($id, $userId);
        if ($updated === null) {
            throw new \RuntimeException('Failed to read back updated trade.');
        }
        return $updated;
    }

    public function serialize(array $trade): array
    {
        return [
            'id' => (int) $trade['id'],
            'symbol' => $trade['symbol'],
            'direction' => $trade['direction'],
            'entryPrice' => self::trimZeros((string) $trade['entry_price']),
            'exitPrice' => self::trimZeros((string) $trade['exit_price']),
            'volume' => self::trimZeros((string) $trade['volume']),
            'contractSize' => self::trimZeros((string) $trade['contract_size']),
            'commission' => self::trimZeros((string) $trade['commission']),
            'swap' => self::trimZeros((string) $trade['swap']),
            'profitLoss' => self::trimZeros((string) $trade['profit_loss']),
            'rMultiple' => $trade['r_multiple'] === null ? null : self::trimZeros((string) $trade['r_multiple']),
            'stopLoss' => $trade['stop_loss'] === null ? null : self::trimZeros((string) $trade['stop_loss']),
            'takeProfit' => $trade['take_profit'] === null ? null : self::trimZeros((string) $trade['take_profit']),
            'accountId' => $trade['account_id'] === null ? null : (int) $trade['account_id'],
            'openTime' => $trade['open_time'],
            'closeTime' => $trade['close_time'],
            'strategyTag' => $trade['strategy_tag'],
            'emotionalScore' => $trade['emotional_score'] === null ? null : (int) $trade['emotional_score'],
            'notes' => $trade['notes'],
            'source' => $trade['source'],
            'createdAt' => $trade['created_at'],
            'updatedAt' => $trade['updated_at'],
        ];
    }

    /** نگاشت ستون‌های snake_case دیتابیس به camelCase مورد انتظار buildTrade */
    private static function snakeToCamel(array $row): array
    {
        $map = [
            'account_id' => 'accountId',
            'contract_size' => 'contractSize',
            'entry_price' => 'entryPrice',
            'exit_price' => 'exitPrice',
            'stop_loss' => 'stopLoss',
            'take_profit' => 'takeProfit',
            'open_time' => 'openTime',
            'close_time' => 'closeTime',
            'strategy_tag' => 'strategyTag',
            'emotional_score' => 'emotionalScore',
        ];
        foreach ($map as $snake => $camel) {
            if (array_key_exists($snake, $row)) {
                $row[$camel] = $row[$snake];
            }
        }
        return $row;
    }

    private static function dec(mixed $value, string $field, int $maxIntegerDigits, int $maxFractionDigits): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new ValidationException('Invalid numeric amount.', [$field => ['code' => 'NOT_NUMERIC', 'messageKey' => 'errors.validation.numeric', 'params' => []]]);
        }
        $value = trim((string) $value);
        $pattern = '/\A-?\d{1,' . $maxIntegerDigits . '}(?:\.\d{1,' . $maxFractionDigits . '})?\z/D';
        if ($value === '' || !preg_match($pattern, $value)) {
            throw new ValidationException('Invalid numeric amount.', [$field => ['code' => 'INVALID_DECIMAL', 'messageKey' => 'errors.validation.decimal', 'params' => ['maxIntegerDigits' => $maxIntegerDigits, 'maxFractionDigits' => $maxFractionDigits]]]);
        }
        return bcadd($value, '0', 8);
    }

    private static function optionalText(mixed $value, string $field, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > $maxLength) {
            throw new ValidationException('Invalid text value.', [$field => ['code' => 'INVALID_LENGTH', 'messageKey' => 'errors.validation.maxLength', 'params' => ['max' => $maxLength]]]);
        }
        return $value;
    }

    private static function toMysqlDatetime(mixed $value): string
    {
        if (is_string($value) && $value !== '' && strlen($value) <= 64) {
            $ts = strtotime($value);
            if ($ts !== false) {
                return gmdate('Y-m-d H:i:s', $ts);
            }
        }
        throw new ValidationException('Invalid date and time.', ['time' => ['code' => 'INVALID_DATETIME', 'messageKey' => 'errors.validation.datetime', 'params' => []]]);
    }

    private static function trimZeros(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }
}
