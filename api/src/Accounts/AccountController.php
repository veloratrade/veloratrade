<?php

declare(strict_types=1);

namespace Velora\Accounts;

use PDO;
use Velora\Core\Config;
use Velora\Core\Database;
use Velora\Core\Exceptions\ApiException;
use Velora\Core\Exceptions\ValidationException;
use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Core\Validation;

/** HTTP layer for shared account and MetaApi lifecycle routes. */
final class AccountController
{
    private const PROVIDERS = ['MT4', 'MT5', 'MANUAL'];
    private const STATUSES = ['connected', 'error', 'disconnected'];

    public function __construct(
        private readonly AccountRepository $repository = new AccountRepository(),
        private readonly MetaApiService $metaapi = new MetaApiService(),
    ) {
    }

    public function index(Request $request): never
    {
        $accounts = array_map(
            [$this, 'serialize'],
            $this->repository->listByUser((int) $request->attributes['user_id'])
        );
        Response::json(['accounts' => $accounts]);
    }

    public function store(Request $request): never
    {
        Validation::assert($request->body, [
            'provider' => 'required|string|in:' . implode(',', self::PROVIDERS),
            'label' => 'string|max:120',
            'accountNumber' => 'string|max:32',
            'currency' => 'string|max:3',
            'leverage' => 'string|max:16',
            'status' => 'string|in:' . implode(',', self::STATUSES),
        ]);

        $currency = mb_strtoupper(trim((string) ($request->body['currency'] ?? 'USD')));
        $accountNumber = trim((string) ($request->body['accountNumber'] ?? ''));
        $leverage = trim((string) ($request->body['leverage'] ?? ''));
        if (!preg_match('/\A[A-Z]{3}\z/D', $currency)) {
            throw new ValidationException('Invalid account currency.', ['currency' => ['code' => 'INVALID_FORMAT', 'messageKey' => 'errors.validation.format', 'params' => []]]);
        }
        if ($accountNumber !== '' && !preg_match('/\A[A-Za-z0-9*._\-]{1,32}\z/D', $accountNumber)) {
            throw new ValidationException('Invalid account number.', ['accountNumber' => ['code' => 'INVALID_FORMAT', 'messageKey' => 'errors.validation.format', 'params' => []]]);
        }
        if ($leverage !== '' && !preg_match('/\A(?:1:)?[1-9]\d{0,7}\z/D', $leverage)) {
            throw new ValidationException('Invalid leverage.', ['leverage' => ['code' => 'INVALID_FORMAT', 'messageKey' => 'errors.validation.format', 'params' => []]]);
        }

        $userId = (int) $request->attributes['user_id'];
        $quota = max(1, (int) Config::get('metaapi.max_accounts_per_user', 10));
        if ($this->repository->countByUser($userId) >= $quota) {
            throw new ApiException('Account quota exceeded.', 429, 'ACCOUNT_QUOTA_EXCEEDED');
        }
        $provider = (string) $request->body['provider'];
        $id = Database::transaction(function (PDO $pdo) use (
            $userId,
            $quota,
            $provider,
            $request,
            $accountNumber,
            $currency,
            $leverage,
        ): int {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $lock = $pdo->prepare(
                'SELECT id FROM users WHERE id=:id' . ($driver === 'mysql' ? ' FOR UPDATE' : '')
            );
            $lock->execute(['id' => $userId]);
            if ($lock->fetchColumn() === false) {
                throw new ApiException('User not found.', 404, 'NOT_FOUND');
            }
            if ($this->repository->countByUser($userId) >= $quota) {
                throw new ApiException('Account quota exceeded.', 429, 'ACCOUNT_QUOTA_EXCEEDED');
            }
            return $this->repository->create([
                'user_id' => $userId,
                'provider' => $provider,
                'platform' => $provider,
                'label' => trim((string) ($request->body['label'] ?? '')),
                'account_number_masked' => $accountNumber,
                'currency' => $currency,
                'leverage' => $leverage !== '' ? $leverage : null,
                'status' => $request->body['status'] ?? 'disconnected',
                'balance' => '0.00',
                'equity' => '0.00',
            ]);
        });
        Response::json(['account' => $this->serialize($this->repository->findByIdForUser($id, $userId))], 201);
    }

    /** POST /api/v1/accounts/connect-metaapi */
    public function connectMetaApi(Request $request): never
    {
        Validation::assert($request->body, [
            'mt_login' => 'required|string|max:100',
            'investorPassword' => 'string|max:100',
            'server' => 'string|max:100',
            'provider' => 'string|in:MT4,MT5,MANUAL',
            'label' => 'string|max:120',
            'broker' => 'string|max:100',
            'account_type' => 'string|max:32',
        ]);

        $idempotencyKey = trim((string) ($request->headers['idempotency-key'] ?? ''));
        if (preg_match('/\A[A-Za-z0-9._~:+\/-]{16,128}\z/D', $idempotencyKey) !== 1) {
            throw new ValidationException('A valid Idempotency-Key header is required.', [
                'Idempotency-Key' => ['code' => 'REQUIRED', 'messageKey' => 'errors.validation.required', 'params' => []],
            ]);
        }

        $login = trim((string) ($request->body['mt_login'] ?? ''));
        $server = trim((string) ($request->body['server'] ?? ''));
        $password = trim((string) ($request->body['investorPassword'] ?? ''));
        $provider = mb_strtoupper(trim((string) ($request->body['provider'] ?? 'MT4')));
        if (str_contains($login, '@')) {
            [$login, $embeddedServer] = array_map('trim', explode('@', $login, 2));
            if ($server === '') {
                $server = $embeddedServer;
            }
        }
        if (str_contains($login, ':')) {
            [$login, $embeddedPassword] = array_map('trim', explode(':', $login, 2));
            if ($password === '') {
                $password = $embeddedPassword;
            }
        }
        if ($password === '') {
            throw new ValidationException('Validation failed.', [
                'investorPassword' => ['code' => 'REQUIRED', 'messageKey' => 'errors.validation.required', 'params' => []],
            ]);
        }
        if ($server === '') {
            $server = match (true) {
                preg_match('/^6\d{6,}/', $login) === 1 => 'Exness-Demo',
                preg_match('/^7\d{6,}/', $login) === 1 => 'Alpari-Demo',
                default => 'ICMarkets-Demo',
            };
        }
        if (preg_match('/\A\d{1,32}\z/D', $login) !== 1) {
            throw new ValidationException('Invalid MetaTrader login.', ['mt_login' => ['code' => 'INVALID_FORMAT', 'messageKey' => 'errors.validation.format', 'params' => []]]);
        }
        if (preg_match('/\A[A-Za-z0-9._\- ]{1,100}\z/D', $server) !== 1) {
            throw new ValidationException('Invalid MetaTrader server.', ['server' => ['code' => 'INVALID_FORMAT', 'messageKey' => 'errors.validation.format', 'params' => []]]);
        }

        $broker = trim((string) ($request->body['broker'] ?? explode('-', $server)[0]));
        $accountType = trim((string) ($request->body['account_type'] ?? 'STANDARD'));
        if (preg_match('/[<>"\'\x00-\x1F\x7F]/u', $broker)
            || preg_match('/\A[A-Za-z0-9 _\-]{1,32}\z/D', $accountType) !== 1) {
            throw new ValidationException('Invalid account metadata.', ['broker' => ['code' => 'INVALID_FORMAT', 'messageKey' => 'errors.validation.format', 'params' => []]]);
        }
        if (str_contains(mb_strtoupper($server), 'MT5')) {
            $provider = 'MT5';
        } elseif (!in_array($provider, ['MT4', 'MT5'], true)) {
            $provider = 'MT4';
        }

        $account = $this->metaapi->connect((int) $request->attributes['user_id'], [
            'provider' => $provider,
            'server' => $server,
            'mt_login' => $login,
            'investorPassword' => $password,
            'label' => trim((string) ($request->body['label'] ?? '')),
            'broker' => $broker,
            'account_type' => $accountType,
        ], $idempotencyKey);
        Response::json([
            'account' => $this->serialize($account),
            'messageKey' => 'accounts.connectingQueued',
            'params' => (object) [],
        ], 201);
    }

    /** Queue-only HTTP path. Provider work is worker-exclusive. */
    public function sync(Request $request, array $params): never
    {
        $userId = (int) $request->attributes['user_id'];
        $accountId = (int) $params['id'];
        $account = $this->repository->findByIdForUser($accountId, $userId);
        if ($account === null) {
            Response::error('Account not found.', 404, 'NOT_FOUND');
        }
        if (empty($account['metaapi_account_id'])) {
            throw new ValidationException('Only MetaApi accounts can be synchronized.', [
                'account' => ['code' => 'METAAPI_REQUIRED', 'messageKey' => 'errors.validation.invalid', 'params' => []],
            ]);
        }
        $job = (new SyncJobRepository())->enqueue(
            $accountId,
            $userId,
            'INCREMENTAL',
            ['trigger' => 'manual'],
        );
        Response::json([
            'jobId' => $job['id'],
            'status' => 'queued',
            'deduplicated' => !$job['enqueued'],
        ], 202);
    }

    public function detectServer(Request $request): never
    {
        $rawLogin = $request->body['mt_login'] ?? $request->body['accountNumber'] ?? '';
        $login = (is_string($rawLogin) || is_int($rawLogin)) ? trim((string) $rawLogin) : '';
        if (preg_match('/\A\d{1,32}\z/D', $login) !== 1) {
            Response::error('Invalid mt_login.', 422, 'VALIDATION_ERROR');
        }
        $common = [
            'ICMarkets-Demo','ICMarkets-Live','ICMarkets-MT5',
            'Exness-Demo','Exness-Real','Exness-MT5',
            'Alpari-Demo','Alpari-Live','Alpari-MT5',
            'XM-Demo','XM-Real','FBS-Demo','FBS-Real',
            'RoboForex-Demo','Pepperstone-Demo','Tickmill-Demo','Deriv-Demo','OANDA-Demo',
        ];
        $suggested = match (true) {
            preg_match('/^5\d{6,}/', $login) === 1 => ['ICMarkets-Demo','ICMarkets-Live','Pepperstone-Demo'],
            preg_match('/^6\d{6,}/', $login) === 1 => ['Exness-Demo','Exness-Real'],
            default => array_slice($common, 0, 5),
        };
        Response::json([
            'mt_login' => $login,
            'suggestedServers' => array_slice($suggested, 0, 5),
            'allServers' => $common,
            'messageKey' => 'accounts.detectServerHint',
            'nextStepKey' => 'accounts.detectServerNextStep',
            'params' => (object) [],
        ]);
    }

    public function syncStatus(Request $request, array $params): never
    {
        $userId = (int) $request->attributes['user_id'];
        $accountId = (int) $params['id'];
        $account = $this->repository->findByIdForUser($accountId, $userId);
        if ($account === null) {
            Response::error('Account not found.', 404, 'NOT_FOUND');
        }
        $recent = array_map(static fn (array $job): array => [
            'id' => (int) $job['id'],
            'type' => $job['type'],
            'status' => $job['status'],
            'attempts' => (int) $job['attempts'],
            'createdAt' => $job['created_at'],
            'startedAt' => $job['started_at'],
            'completedAt' => $job['completed_at'],
            'availableAt' => $job['available_at'],
        ], (new SyncJobRepository())->recentForAccount($accountId, 5));
        Response::json([
            'account' => $this->serialize($account),
            'syncStatus' => $account['sync_status'] ?? 'DISCONNECTED',
            'lastSyncedAt' => $account['last_synced_at'] ?? null,
            'recentJobs' => $recent,
        ]);
    }

    public function destroy(Request $request, array $params): never
    {
        $this->metaapi->deleteAccount((int) $params['id'], (int) $request->attributes['user_id']);
        Response::json(['deleted' => true]);
    }

    private function serialize(?array $account): ?array
    {
        if ($account === null) {
            return null;
        }
        return [
            'id' => (int) $account['id'],
            'provider' => $account['provider'],
            'platform' => $account['platform'] ?? $account['provider'],
            'broker' => $account['broker'] ?? null,
            'server' => $account['server'] ?? null,
            'mtLogin' => $account['mt_login'] ?? $account['account_number_masked'] ?? null,
            'label' => $account['label'],
            'accountNumber' => $account['account_number_masked'],
            'currency' => $account['currency'],
            'leverage' => $account['leverage'],
            'status' => $account['status'],
            'syncStatus' => $account['sync_status'] ?? 'DISCONNECTED',
            'metaapiAccountId' => $account['metaapi_account_id'] ?? null,
            'lastSyncedAt' => $account['last_synced_at'] ?? null,
            'connectedAt' => $account['connected_at'] ?? null,
            'balance' => (string) $account['balance'],
            'equity' => (string) $account['equity'],
            'createdAt' => $account['created_at'],
        ];
    }
}
