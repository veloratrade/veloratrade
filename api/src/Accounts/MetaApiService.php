<?php

declare(strict_types=1);

namespace Velora\Accounts;

use Closure;
use PDO;
use Velora\Core\Config;
use Velora\Core\Crypto;
use Velora\Core\Database;
use Velora\Core\Exceptions\ApiException;
use Velora\Core\Exceptions\ConflictException;
use Velora\Trades\MetaApiDealAssembler;
use Velora\Trades\MetaApiInstantResolver;

/**
 * MetaApi bridge with durable provisioning operations and reconciliation-safe
 * external lifecycle handling.
 */
final class MetaApiService
{
    private string $apiToken;
    private string $provisioningBaseUrl;
    private string $clientBaseUrl;
    private AccountRepository $accounts;
    private SyncJobRepository $jobs;
    private MetaApiOperationRepository $operations;
    private ?Closure $transport;
    private MetaApiInstantResolver $instants;
    private MetaApiDealAssembler $assembler;
    private MetaApiFillRepository $fills;

    public function __construct(
        ?AccountRepository $accounts = null,
        ?SyncJobRepository $jobs = null,
        ?MetaApiOperationRepository $operations = null,
        ?callable $transport = null,
        ?MetaApiInstantResolver $instants = null,
        ?MetaApiDealAssembler $assembler = null,
        ?MetaApiFillRepository $fills = null,
    ) {
        $this->accounts = $accounts ?? new AccountRepository();
        $this->jobs = $jobs ?? new SyncJobRepository();
        $this->operations = $operations ?? new MetaApiOperationRepository();
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
        $this->instants = $instants ?? new MetaApiInstantResolver();
        $this->assembler = $assembler ?? new MetaApiDealAssembler($this->instants);
        $this->fills = $fills ?? new MetaApiFillRepository();
        // Phase C: read via IntegrationConfigResolver so an Admin-managed value
        // (encrypted store) is effective at runtime, with ENV / velora.env as
        // the documented fallbacks. Precedence lives in ONE place.
        $this->apiToken = \Velora\Core\IntegrationConfigResolver::metaApiToken();
        $configured = rtrim(\Velora\Core\IntegrationConfigResolver::metaApiBaseUrl(), '/');
        $this->provisioningBaseUrl = str_replace('mt-client-api-v1', 'mt-provisioning-api-v1', $configured);
        $this->clientBaseUrl = str_replace('mt-provisioning-api-v1', 'mt-client-api-v1', $configured);
    }

    private function isDevLikeEnvironment(): bool
    {
        return Config::isDevelopmentEnvironment();
    }

    private function normalizeExternalIdentifier(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $id = trim((string) $value);
        if ($id === '' || strlen($id) > 64 || preg_match('/\A[A-Za-z0-9._:-]+\z/D', $id) !== 1) {
            return null;
        }
        return $id;
    }

    private function requireExternalIdentifier(mixed $value, string $label): string
    {
        $id = $this->normalizeExternalIdentifier($value);
        if ($id === null) {
            throw new \RuntimeException('MetaApi returned an invalid ' . $label . '.');
        }
        return $id;
    }

    /**
     * Phase 4 — resolve a MetaApi timestamp to a canonical UTC instant.
     *
     * Accepts ONLY offset-explicit absolute instants (deal/position/order
     * `time`, `doneTime`, ServerTime `time` — ISO with trailing Z/offset).
     * A naive `brokerTime` (no offset) is NOT parseable to an absolute instant
     * and yields null — it is never run through strtotime/gmdate or a default
     * timezone. Returns null on empty/naive/unresolved; callers treat a null
     * instant as "unresolved" and never fabricate UTC.
     */
    private function resolveMetaInstant(mixed $value): ?string
    {
        return $this->instants->canonicalUtc($value);
    }

    private function normalizeExternalDecimal(mixed $value, int $maxIntegerDigits, int $maxFractionDigits): ?string
    {
        if (is_int($value)) {
            $candidate = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }
            $candidate = number_format($value, $maxFractionDigits, '.', '');
        } elseif (is_string($value)) {
            $candidate = trim($value);
        } else {
            return null;
        }

        if ($candidate === '' || strlen($candidate) > $maxIntegerDigits + $maxFractionDigits + 3
            || preg_match('/\A([+-]?)(\d+)(?:\.(\d+))?\z/D', $candidate, $m) !== 1) {
            return null;
        }

        $integer = ltrim($m[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $m[3] ?? '';
        if (strlen($integer) > $maxIntegerDigits || strlen($fraction) > $maxFractionDigits) {
            return null;
        }

        $fraction = rtrim($fraction, '0');
        $isZero = $integer === '0' && $fraction === '';
        $sign = !$isZero && $m[1] === '-' ? '-' : '';
        return $sign . $integer . ($fraction !== '' ? '.' . $fraction : '');
    }

    private function brokerAdjustmentToCost(mixed $value): ?string
    {
        $signed = $this->normalizeExternalDecimal($value, 10, 8);
        if ($signed === null || $signed === '0') {
            return $signed;
        }
        return str_starts_with($signed, '-') ? substr($signed, 1) : '-' . $signed;
    }

    private function isPositiveDecimal(string $value): bool
    {
        return !str_starts_with($value, '-') && $value !== '0';
    }

    private function normalizeExternalText(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            return null;
        }
        return $value;
    }

    /**
     * Phase 4 — normalize ONE MetaApi history deal (a single FILL) into the
     * internal deal shape consumed by {@see MetaApiDealAssembler}. Deals are
     * paired into closed positions by positionId there.
     *
     * Timestamp rule (Phase 2E verification): ONLY the offset-explicit `time`
     * field yields an absolute instant. The naive `brokerTime` is preserved as
     * evidence but NEVER used as an instant. A deal whose `time` is naive or
     * missing keeps time_utc = null (assembler then leaves the boundary
     * unresolved rather than fabricating UTC).
     *
     * Returns null for records that are not market fills (balance/credit deals
     * with no symbol) or that lack a usable deal identity/price/volume.
     */
    private function normalizeExternalDeal(array $deal): ?array
    {
        $externalId = $this->normalizeExternalIdentifier(
            $deal['id'] ?? $deal['dealId'] ?? $deal['ticket'] ?? null
        );
        $positionId = $this->normalizeExternalIdentifier(
            $deal['positionId'] ?? $deal['position_id'] ?? null
        );
        $orderId = $this->normalizeExternalIdentifier($deal['orderId'] ?? $deal['order_id'] ?? null);

        $symbol = $this->normalizeExternalText($deal['symbol'] ?? null, 32);
        $rawType = $deal['type'] ?? null;
        $type = is_string($rawType) && strlen($rawType) <= 50 ? strtolower(trim($rawType)) : '';
        $direction = match ($type) {
            'buy', 'deal_type_buy' => 'buy',
            'sell', 'deal_type_sell' => 'sell',
            default => null,
        };
        $entryType = $this->normalizeEntryType($deal['entryType'] ?? $deal['entry_type'] ?? null);

        $price = $this->normalizeExternalDecimal(
            $deal['price'] ?? $deal['openPrice'] ?? $deal['open_price'] ?? $deal['closePrice'] ?? $deal['close_price'] ?? null,
            10,
            8
        );
        $volume = $this->normalizeExternalDecimal($deal['volume'] ?? null, 10, 8);
        $profit = $this->normalizeExternalDecimal($deal['profit'] ?? $deal['profit_loss'] ?? null, 16, 8);
        // Normalize to a COST (negative reduces PnL; zero stays zero). MetaApi
        // signs commission/swap variably across brokers, so canonicalize here.
        $commissionCost = $this->brokerAdjustmentToCost($deal['commission'] ?? 0);
        $swapCost = $this->brokerAdjustmentToCost($deal['swap'] ?? 0);

        // Absolute instant ONLY from offset-explicit `time` (never brokerTime).
        $timeRaw = is_string($deal['time'] ?? null) ? trim((string) $deal['time']) : null;
        $timeUtc = $this->resolveMetaInstant($deal['time'] ?? null);
        // Evidence only — never used to derive an instant.
        $brokerTime = $this->normalizeExternalText($deal['brokerTime'] ?? $deal['broker_time'] ?? null, 64);

        // A trade fill needs identity, symbol, direction, a positive price and
        // volume. entryType may be absent on some payloads (assembler will skip
        // if it cannot pair); time_utc may be null (unresolved) at this layer.
        if ($externalId === null || $symbol === null || $direction === null
            || $price === null || !$this->isPositiveDecimal($price)
            || $volume === null || !$this->isPositiveDecimal($volume)) {
            return null;
        }

        return [
            'external_deal_id' => $externalId,
            'position_id' => $positionId,
            'order_id' => $orderId,
            'entry_type' => $entryType,
            'symbol' => $symbol,
            'direction' => $direction,
            'price' => $price,
            'volume' => $volume,
            'profit' => $profit ?? '0',
            'commission' => $commissionCost ?? '0',
            'swap' => $swapCost ?? '0',
            'time_raw' => $timeRaw,
            'time_utc' => $timeUtc,
            'broker_time' => $brokerTime,
        ];
    }

    /** Map MetaApi entryType to 'in' | 'out' | null (inout/balance/credit => null). */
    private function normalizeEntryType(mixed $value): ?string
    {
        if (is_int($value)) {
            // MT5 DEAL_ENTRY_IN=0, DEAL_ENTRY_OUT=1 (DEAL_ENTRY_INOUT=2, OUT_BY=3).
            return match ($value) {
                0 => 'in',
                1 => 'out',
                default => null,
            };
        }
        if (!is_string($value)) {
            return null;
        }
        $e = strtolower(trim($value));
        return match ($e) {
            'in', 'deal_entry_in', 'entry_in' => 'in',
            'out', 'deal_entry_out', 'entry_out' => 'out',
            default => null,
        };
    }

    private function normalizeAccountInformation(array $data): array
    {
        $balance = $this->normalizeExternalDecimal($data['balance'] ?? null, 13, 2);
        $equity = $this->normalizeExternalDecimal($data['equity'] ?? null, 13, 2);
        $margin = $this->normalizeExternalDecimal($data['margin'] ?? 0, 13, 2);
        $freeMargin = $this->normalizeExternalDecimal($data['freeMargin'] ?? 0, 13, 2);
        $marginLevel = $this->normalizeExternalDecimal($data['marginLevel'] ?? 0, 13, 2);
        $rawLeverage = $data['leverage'] ?? 500;
        if (is_int($rawLeverage)) {
            $leverageNumber = $rawLeverage;
        } elseif (is_float($rawLeverage) && is_finite($rawLeverage) && floor($rawLeverage) === $rawLeverage) {
            $leverageNumber = (int) $rawLeverage;
        } elseif (is_string($rawLeverage)) {
            $rawLeverage = trim($rawLeverage);
            if (str_starts_with($rawLeverage, '1:')) {
                $rawLeverage = substr($rawLeverage, 2);
            }
            $leverageNumber = preg_match('/\A\d{1,7}\z/D', $rawLeverage) === 1 ? (int) $rawLeverage : 0;
        } else {
            $leverageNumber = 0;
        }

        $currency = strtoupper(trim(is_string($data['currency'] ?? null) ? $data['currency'] : 'USD'));
        $broker = $this->normalizeExternalText($data['broker'] ?? 'Vittaverse', 100);
        $server = $this->normalizeExternalText($data['server'] ?? 'Vittaverse-Server', 100);
        if ($balance === null || $equity === null || $margin === null || $freeMargin === null || $marginLevel === null
            || $leverageNumber < 1 || $leverageNumber > 1_000_000
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1 || $broker === null || $server === null) {
            throw new \RuntimeException('MetaApi returned malformed account information.');
        }
        return [
            'balance' => $balance,
            'equity' => $equity,
            'margin' => $margin,
            'freeMargin' => $freeMargin,
            'marginLevel' => $marginLevel,
            'leverage' => '1:' . $leverageNumber,
            'currency' => $currency,
            'broker' => $broker,
            'server' => $server,
        ];
    }

    /** Provision once or reconcile before reusing the stable provider idempotency key. */
    public function connect(int $userId, array $input, string $clientIdempotencyKey): array
    {
        $provider = strtoupper((string) $input['provider']);
        $server = trim((string) $input['server']);
        $login = trim((string) $input['mt_login']);
        $password = (string) $input['investorPassword'];
        $fingerprint = hash('sha256', implode("\0", [
            (string) $userId,
            $provider,
            strtolower($server),
            $login,
            (string) ($input['account_type'] ?? 'STANDARD'),
        ]));

        // Validate encryption before any external side effect.
        $credentials = Crypto::encrypt((string) json_encode([
            'server' => $server,
            'mt_login' => $login,
            'investorPassword' => $password,
            'provider' => $provider,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        // Serialize quota reservation per user. The durable operation itself is
        // the reservation, so concurrent connects cannot all pass a plain
        // account count before any local account has been created.
        $created = Database::transaction(function (PDO $pdo) use (
            $userId,
            $clientIdempotencyKey,
            $fingerprint,
        ): array {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $lock = $pdo->prepare(
                'SELECT id FROM users WHERE id=:id' . ($driver === 'mysql' ? ' FOR UPDATE' : '')
            );
            $lock->execute(['id' => $userId]);
            if ($lock->fetchColumn() === false) {
                throw new ApiException('User not found.', 404, 'NOT_FOUND');
            }

            $reserved = $this->operations->createOrGetConnect(
                $userId,
                $clientIdempotencyKey,
                $fingerprint,
            );
            if ($reserved['created']) {
                $quota = max(1, (int) Config::get('metaapi.max_accounts_per_user', 10));
                $used = $this->accounts->countByUser($userId)
                    + $this->operations->countOpenReservationsForUser($userId);
                if ($used > $quota) {
                    throw new ApiException('Account quota exceeded.', 429, 'ACCOUNT_QUOTA_EXCEEDED');
                }
            }
            return $reserved;
        });
        $operation = $created['operation'];
        $operationId = (int) $operation['id'];
        $operationKey = (string) $operation['operation_key'];
        $marker = (string) $operation['provider_marker'];

        if (in_array((string) $operation['status'], [
            'DELETE_PENDING',
            'DELETE_RECONCILIATION_REQUIRED',
            'EXTERNAL_DELETED',
            'DELETED',
        ], true)) {
            throw new ConflictException(
                'This idempotent connection is being deleted or was already deleted.',
                'METAAPI_OPERATION_DELETED'
            );
        }
        if ((string) $operation['status'] === 'COMPLETED' && !empty($operation['account_id'])) {
            $existingAccount = $this->accounts->findByIdForUser((int) $operation['account_id'], $userId);
            if ($existingAccount !== null) {
                // Repair a prior local queue-publication failure on replay.
                $job = $this->jobs->enqueue((int) $existingAccount['id'], $userId, 'HISTORICAL', ['months' => 12]);
                if ($job['enqueued']) {
                    $this->accounts->updateSyncStatus((int) $existingAccount['id'], 'SYNCING');
                    $existingAccount = $this->accounts->findByIdForUser((int) $existingAccount['id'], $userId);
                }
                if ($existingAccount === null) {
                    throw new \RuntimeException('Connected account could not be reloaded.');
                }
                $existingAccount['_sync_job_id'] = $job['id'];
                return $existingAccount;
            }
        }

        if ($created['created']) {
            $duplicate = $this->accounts->findByConnectionIdentity($userId, $provider, $server, $login);
            if ($duplicate !== null) {
                $this->operations->markFailed($operationId, 'DUPLICATE_CONNECTION');
                throw new ConflictException('This trading account is already connected.', 'DUPLICATE_CONNECTION');
            }
        }

        $providerAccountId = null;
        try {
            if (!$created['created']) {
                // Any prior attempt may have ended after the provider accepted
                // creation but before Velora observed/persisted the response.
                $providerAccountId = $this->reconcileProviderMarker($marker);
            }
            if ($providerAccountId === null && !empty($operation['provider_account_id'])) {
                // A durable provider id is useful evidence, but a retried real
                // operation still reconciles by marker above before proceeding.
                $providerAccountId = $this->requireExternalIdentifier(
                    $operation['provider_account_id'],
                    'account identifier'
                );
            }
            if ($providerAccountId === null) {
                $this->operations->incrementAttempt($operationId);
                if ($this->apiToken === '' && $this->isDevLikeEnvironment()) {
                    $providerAccountId = 'mock_' . substr($operationKey, 0, 32);
                } else {
                    $providerAccountId = $this->provisionMetaApiAccount(
                        $provider,
                        $server,
                        $login,
                        $password,
                        $marker,
                        $operationKey,
                    );
                }
            }
            $this->operations->markProviderCreated($operationId, $providerAccountId);
            $this->deployMetaApiAccount($providerAccountId, $operationKey);
        } catch (ProviderRequestException $e) {
            if ($e->ambiguous) {
                $this->operations->markAmbiguous($operationId, $e->errorCode);
                throw new ApiException(
                    'MetaApi outcome is ambiguous; reconciliation is required before retry.',
                    503,
                    'METAAPI_RECONCILIATION_REQUIRED'
                );
            }
            $this->operations->markFailed($operationId, $e->errorCode);
            throw new ApiException('MetaApi provisioning failed.', 502, $e->errorCode);
        } catch (\Throwable $e) {
            $this->operations->markAmbiguous($operationId, 'METAAPI_LOCAL_OUTCOME_UNKNOWN');
            throw $e;
        }

        $accountId = !empty($operation['account_id']) ? (int) $operation['account_id'] : null;
        if ($accountId === null) {
            try {
                // Account creation/linkage and lifecycle completion are one
                // local commit. A provider resource is never discarded merely
                // because this transaction needs reconciliation.
                $accountId = Database::transaction(function () use (
                    $userId,
                    $provider,
                    $server,
                    $login,
                    $input,
                    $providerAccountId,
                    $credentials,
                    $operationId,
                ): int {
                    $existing = $this->accounts->findByConnectionIdentity($userId, $provider, $server, $login);
                    if ($existing !== null) {
                        if (empty($existing['metaapi_account_id'])
                            || !hash_equals($providerAccountId, (string) $existing['metaapi_account_id'])) {
                            throw new ConflictException(
                                'Connection identity is linked to a different provider account.',
                                'METAAPI_CONNECTION_IDENTITY_CONFLICT'
                            );
                        }
                        $id = (int) $existing['id'];
                    } else {
                        $id = $this->accounts->create([
                            'user_id' => $userId,
                            'provider' => $provider,
                            'platform' => $provider,
                            'server' => $server,
                            'mt_login' => $login,
                            'broker' => $input['broker'] ?? null,
                            'account_type' => $input['account_type'] ?? 'STANDARD',
                            'metaapi_account_id' => $providerAccountId,
                            'sync_status' => 'CONNECTING',
                            'connection_credentials_encrypted' => $credentials,
                            'label' => trim((string) ($input['label'] ?? '')),
                        ]);
                    }
                    $this->operations->markCompleted($operationId, $id, $providerAccountId);
                    return $id;
                });
            } catch (\Throwable $e) {
                // The external resource is intentionally not deleted here: the
                // operation preserves enough marker state for a safe retry.
                $this->operations->markAmbiguous($operationId, 'LOCAL_PERSISTENCE_FAILED');
                throw new ApiException(
                    'External account exists but local reconciliation is required.',
                    503,
                    'METAAPI_RECONCILIATION_REQUIRED'
                );
            }
        }
        $job = $this->jobs->enqueue($accountId, $userId, 'HISTORICAL', ['months' => 12]);
        $this->accounts->updateSyncStatus($accountId, 'SYNCING');

        // Information refresh is non-blocking after durable local completion.
        try {
            $info = $this->fetchAccountInformation($providerAccountId);
            if ($info !== null) {
                $this->accounts->updateBalances(
                    $accountId,
                    (string) $info['balance'],
                    (string) $info['equity'],
                    (string) $info['currency'],
                    (string) $info['leverage'],
                );
            }
        } catch (\Throwable) {
        }

        $account = $this->accounts->findByIdForUser($accountId, $userId);
        if ($account === null) {
            throw new \RuntimeException('Connected account could not be reloaded.');
        }
        $account['_sync_job_id'] = $job['id'];
        return $account;
    }

    /** External-first deletion; only Velora-marked provider resources are automatic. */
    public function deleteAccount(int $accountId, int $userId): void
    {
        $account = $this->accounts->findByIdForUser($accountId, $userId);
        if ($account === null) {
            throw new ApiException('Account not found.', 404, 'NOT_FOUND');
        }
        if (($account['provider'] ?? 'MANUAL') === 'MANUAL' || empty($account['metaapi_account_id'])) {
            $this->accounts->delete($accountId);
            return;
        }

        $operation = $this->operations->findByAccount($accountId);
        if ($operation === null || empty($operation['provider_marker']) || empty($operation['provider_account_id'])) {
            throw new ConflictException(
                'Automatic provider deletion is refused because no Velora operation marker is recorded.',
                'METAAPI_MARKER_REQUIRED'
            );
        }
        $operationId = (int) $operation['id'];
        $operationKey = (string) $operation['operation_key'];
        $marker = (string) $operation['provider_marker'];
        $expectedProviderId = $this->requireExternalIdentifier(
            $operation['provider_account_id'],
            'account identifier'
        );
        $externalAlreadyDeleted = (string) $operation['status'] === 'EXTERNAL_DELETED';
        if (!$externalAlreadyDeleted) {
            $this->operations->markDeletePending($operationId);
        }

        try {
            if (!$externalAlreadyDeleted) {
                $remoteId = $this->reconcileProviderMarker($marker);
                if ($remoteId !== null && !hash_equals($expectedProviderId, $remoteId)) {
                    throw new ConflictException(
                        'Provider marker resolved to an unexpected account; automatic deletion was refused.',
                        'METAAPI_MARKER_MISMATCH'
                    );
                }
                if ($remoteId !== null) {
                    $this->deleteMetaApiAccount($remoteId, $operationKey);
                }
                $this->operations->markExternalDeleted($operationId);
            }
        } catch (ProviderRequestException $e) {
            if ($e->ambiguous) {
                $this->operations->markDeleteAmbiguous($operationId, $e->errorCode);
                throw new ApiException(
                    'Provider deletion outcome is ambiguous; local account was retained.',
                    503,
                    'METAAPI_DELETE_RECONCILIATION_REQUIRED'
                );
            }
            throw new ApiException('MetaApi deletion failed; local account was retained.', 502, $e->errorCode);
        }

        // External deletion is durable before one atomic local finalization.
        Database::transaction(function () use ($accountId, $operationId): void {
            $this->accounts->delete($accountId);
            $this->operations->markDeleted($operationId);
        });
    }

    /** Claim and process one fenced queue lease. */
    public function runNextSyncJob(?string $workerId = null): ?array
    {
        $job = $this->jobs->claimNext($workerId);
        if ($job === null) {
            return null;
        }
        $jobId = (int) $job['id'];
        $leaseToken = (string) $job['lease_token'];
        $accountId = (int) $job['account_id'];
        $userId = (int) $job['user_id'];

        try {
            $account = $this->accounts->findInternalById($accountId);
            if ($account === null || (int) $account['user_id'] !== $userId) {
                throw new \RuntimeException('Account not found for sync.');
            }
            // Fetch + durably LEDGER every fill (one row per external deal),
            // then reconcile closed positions from the durable ledger. This
            // converges historical sync and realtime webhook delivery on the
            // same fill set (cross-event pairing, restart/retry safe).
            $deals = $this->fetchHistoricalTrades($account, $job);
            $result = Database::transaction(function (PDO $pdo) use (
                $deals,
                $userId,
                $accountId,
                $jobId,
                $leaseToken,
            ): array {
                foreach ($deals as $deal) {
                    $this->fills->recordFill($pdo, $accountId, $userId, $deal, 'historical');
                }
                $recon = $this->reconcileAccount($pdo, $userId, $accountId);
                if (!$this->jobs->complete($jobId, $leaseToken)) {
                    throw new \RuntimeException('Sync lease was lost before completion.');
                }
                $this->accounts->markSynced($accountId);
                return $recon;
            });
            return [
                'job_id' => $jobId,
                'account_id' => $accountId,
                'inserted' => $result['inserted'],
                'assembled' => $result['assembled'],
                'skipped' => $result['skipped'],
                'fills' => count($deals),
            ];
        } catch (\Throwable $e) {
            $result = $this->jobs->fail($jobId, $leaseToken, $e->getMessage());
            $this->accounts->updateSyncStatus($accountId, 'ERROR', 'MetaApi sync attempt failed.');
            throw new \RuntimeException(
                'MetaApi sync failed; queue status is ' . $result['status'] . '.',
                0,
                $e
            );
        }
    }

    /** Process an already authenticated and durably claimed webhook event. */
    public function processWebhook(array $payload): array
    {
        $metaapiId = $this->normalizeExternalIdentifier($payload['accountId'] ?? $payload['metaapi_account_id'] ?? null);
        $rawType = $payload['type'] ?? $payload['event'] ?? null;
        $type = is_string($rawType) ? strtolower(trim($rawType)) : '';
        if ($metaapiId === null || $type === '' || strlen($type) > 50
            || preg_match('/\A[a-z0-9._:-]+\z/D', $type) !== 1) {
            throw new ApiException('Invalid webhook identifiers.', 422, 'INVALID_WEBHOOK_IDENTIFIERS');
        }

        $account = $this->accounts->findByMetaApiId($metaapiId);
        $inserted = 0;
        $skipped = 0;
        $fills = 0;
        if ($account !== null && in_array($type, ['deal', 'trade', 'history', 'order'], true)) {
            // Durable fill ledger + reconciliation: persist each incoming fill
            // once (by account+deal id), then assemble closed positions from
            // ALL ledgered fills for the account. This pairs IN/OUT that arrive
            // in SEPARATE webhooks (or before/after a historical sync), and is
            // restart/retry/multi-worker safe. A lone fill is ledgered and waits.
            $eventRef = is_string($payload['eventId'] ?? null)
                ? hash('sha256', $metaapiId . "\0" . $type . "\0" . (string) $payload['eventId'])
                : null;
            $deals = $this->extractWebhookDeals($payload);
            $recon = Database::transaction(function (PDO $pdo) use ($deals, $account, $eventRef): array {
                foreach ($deals as $deal) {
                    $this->fills->recordFill($pdo, (int) $account['id'], (int) $account['user_id'], $deal, 'webhook', $eventRef);
                }
                return $this->reconcileAccount($pdo, (int) $account['user_id'], (int) $account['id']);
            });
            $inserted = $recon['inserted'];
            $skipped = $recon['skipped'];
            $fills = count($deals);
        }
        return [
            'account_id' => $account === null ? null : (int) $account['id'],
            'inserted' => $inserted,
            'skipped' => $skipped,
            'fills' => $fills,
        ];
    }

    /**
     * Reconcile an account's DURABLE fill ledger into closed-position trades.
     *
     * Assembles ALL ledgered trade fills for the account in one transaction:
     *   - fully-paired, fully-closed positions -> insert/upsert one trades row
     *     (idempotent on account + pos-<positionId>); mark their fills aggregated;
     *   - incomplete/open/naive positions -> leave their fills 'received' so a
     *     later webhook or historical sync can complete them (never fabricate);
     *   - positions the assembler definitively skips (missing open/out-of-order)
     *     are marked skipped so they are not retried forever.
     *
     * Must be called inside a transaction.
     *
     * @return array{inserted:int,assembled:int,skipped:int}
     */
    private function reconcileAccount(PDO $pdo, int $userId, int $accountId): array
    {
        // Scope reconciliation to positions that still have unprocessed fills.
        // Already-aggregated positions are not re-assembled (a new fill arriving
        // later flips that position back to 'received' and reopens reassessment).
        $positionIds = $this->fills->pendingPositionIds($pdo, $accountId);
        if ($positionIds === []) {
            return ['inserted' => 0, 'assembled' => 0, 'skipped' => 0];
        }

        $inserted = 0;
        $assembledCount = 0;
        $skippedCount = 0;

        foreach ($positionIds as $positionId) {
            $fills = $this->fills->fillsForPosition($pdo, $accountId, $positionId);
            $result = $this->assembler->assemble($fills);
            $fillIds = $this->fills->fillIdsForPosition($pdo, $accountId, $positionId);
            $key = 'pos-' . $positionId;

            if ($result['trades'] !== []) {
                foreach ($result['trades'] as $trade) {
                    $inserted += $this->insertExternalTrade($pdo, $userId, $accountId, $trade);
                }
                $tradeId = $this->findTradeIdByExternal($pdo, $accountId, $key);
                $this->fills->markAggregated($pdo, $fillIds, $tradeId);
                $assembledCount += count($result['trades']);
            } else {
                $reason = (string) ($result['skipped'][0]['reason'] ?? 'position_pending');
                // Only mark terminal for data that a future fill cannot repair:
                // bad chronology or an unusable direction. "missing" open/close
                // and unresolved/partial positions must stay 'received' — the
                // counterpart fill can arrive in a later webhook/sync.
                if (in_array($reason, ['close_before_open', 'unknown_direction'], true)) {
                    $this->fills->markSkipped($pdo, $fillIds, $reason);
                    $skippedCount++;
                }
            }
        }

        return ['inserted' => $inserted, 'assembled' => $assembledCount, 'skipped' => $skippedCount];
    }

    private function findTradeIdByExternal(PDO $pdo, int $accountId, string $externalDealId): ?int
    {
        $stmt = $pdo->prepare('SELECT id FROM trades WHERE account_id=:a AND external_deal_id=:e LIMIT 1');
        $stmt->execute(['a' => $accountId, 'e' => $externalDealId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function fetchAccountInformation(string $metaapiId): ?array
    {
        $metaapiId = $this->requireExternalIdentifier($metaapiId, 'account identifier');
        $mock = [
            'balance' => '10432.50', 'equity' => '10510.20', 'margin' => '120.00',
            'freeMargin' => '10390.20', 'marginLevel' => '8758.50', 'leverage' => '1:500',
            'currency' => 'USD', 'broker' => 'Vittaverse (Live Demo)', 'server' => 'Vittaverse-Server',
        ];
        if ($this->apiToken === '' || str_starts_with($metaapiId, 'mock_')) {
            if ($this->isDevLikeEnvironment()) {
                return $this->normalizeAccountInformation($mock);
            }
            throw new \RuntimeException('MetaApi account information requires a real provider account.');
        }
        $response = $this->providerRequest(
            'GET',
            $this->clientBaseUrl . "/users/current/accounts/{$metaapiId}/account-information",
            null,
            [],
            15,
        );
        $this->requireSuccess($response, 'METAAPI_ACCOUNT_INFO_FAILED');
        if (!is_array($response['json'])) {
            throw new ProviderRequestException('METAAPI_INVALID_RESPONSE', true);
        }
        return $this->normalizeAccountInformation($response['json']);
    }

    private function provisionMetaApiAccount(
        string $provider,
        string $server,
        string $login,
        string $password,
        string $marker,
        string $operationKey,
    ): string {
        $brokerKeyword = trim((string) explode('-', $server)[0]);
        $response = $this->providerRequest(
            'POST',
            $this->provisioningBaseUrl . '/users/current/accounts',
            [
                'login' => $login,
                'password' => $password,
                'server' => $server,
                'platform' => strtolower($provider) === 'mt5' ? 'mt5' : 'mt4',
                'name' => $marker,
                'magic' => 0,
                'manualTrades' => true,
                'keywords' => $brokerKeyword !== '' ? [$brokerKeyword, 'velora'] : ['velora'],
            ],
            ['Idempotency-Key' => 'velora-' . $operationKey],
            20,
        );
        $this->requireSuccess($response, 'METAAPI_PROVISIONING_FAILED');
        if (!is_array($response['json']) || empty($response['json']['id'])) {
            throw new ProviderRequestException('METAAPI_INVALID_RESPONSE', true);
        }
        return $this->requireExternalIdentifier($response['json']['id'], 'account identifier');
    }

    private function deployMetaApiAccount(string $providerAccountId, string $operationKey): void
    {
        if ($this->apiToken === '' && $this->isDevLikeEnvironment()) {
            return;
        }
        $response = $this->providerRequest(
            'POST',
            $this->provisioningBaseUrl . "/users/current/accounts/{$providerAccountId}/deploy",
            null,
            ['Idempotency-Key' => 'velora-deploy-' . $operationKey],
            20,
        );
        $this->requireSuccess($response, 'METAAPI_DEPLOY_FAILED');
    }

    private function reconcileProviderMarker(string $marker): ?string
    {
        if ($this->apiToken === '' && $this->isDevLikeEnvironment()) {
            return null;
        }
        $response = $this->providerRequest(
            'GET',
            $this->provisioningBaseUrl . '/users/current/accounts',
            null,
            [],
            20,
        );
        $this->requireSuccess($response, 'METAAPI_RECONCILIATION_FAILED');
        $json = $response['json'];
        $items = is_array($json) && isset($json['items']) && is_array($json['items']) ? $json['items'] : $json;
        if (!is_array($items)) {
            throw new ProviderRequestException('METAAPI_INVALID_RESPONSE', true);
        }
        $matches = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['name']) && hash_equals($marker, (string) $item['name'])) {
                $matches[] = $this->requireExternalIdentifier($item['id'] ?? null, 'account identifier');
            }
        }
        if (count($matches) > 1) {
            throw new ProviderRequestException('METAAPI_MARKER_NOT_UNIQUE', true);
        }
        return $matches[0] ?? null;
    }

    private function deleteMetaApiAccount(string $providerAccountId, string $operationKey): void
    {
        $response = $this->providerRequest(
            'DELETE',
            $this->provisioningBaseUrl . "/users/current/accounts/{$providerAccountId}",
            null,
            ['Idempotency-Key' => 'velora-delete-' . $operationKey],
            20,
        );
        if ((int) $response['status'] === 404) {
            return;
        }
        $this->requireSuccess($response, 'METAAPI_DELETE_FAILED');
    }

    /**
     * Fetch history deals and normalize each into the internal DEAL (fill)
     * shape. Position pairing/assembly happens in {@see MetaApiDealAssembler}.
     *
     * @return array<int,array<string,mixed>> normalized deals
     */
    private function fetchHistoricalTrades(array $account, array $job): array
    {
        $providerId = $this->requireExternalIdentifier($account['metaapi_account_id'] ?? null, 'account identifier');
        if ($this->apiToken === '' || str_starts_with($providerId, 'mock_')) {
            if (!$this->isDevLikeEnvironment()) {
                throw new \RuntimeException('Historical sync requires a real MetaApi provider account.');
            }
            // Dev fixtures mirror the REAL history-deals shape: absolute `time`
            // with trailing Z plus naive `brokerTime`, paired by positionId
            // (DEAL_ENTRY_IN / DEAL_ENTRY_OUT). The assembler derives canonical
            // UTC open/close from the Z instants and ignores brokerTime.
            $day = gmdate('Ymd');
            return [
                ['id' => 'dev-xau-in-' . $day, 'positionId' => 'dev-xau-' . $day, 'entryType' => 'DEAL_ENTRY_IN',
                    'symbol' => 'XAU/USD', 'type' => 'buy', 'price' => '2025.5', 'volume' => '0.1',
                    'profit' => '0', 'commission' => '0', 'swap' => '0',
                    'time' => gmdate('Y-m-d\TH:i:s.000\Z', strtotime('-1 day')),
                    'brokerTime' => gmdate('Y-m-d H:i:s.000', strtotime('-1 day +3 hours'))],
                ['id' => 'dev-xau-out-' . $day, 'positionId' => 'dev-xau-' . $day, 'entryType' => 'DEAL_ENTRY_OUT',
                    'symbol' => 'XAU/USD', 'type' => 'buy', 'price' => '2035.2', 'volume' => '0.1',
                    'profit' => '97.00', 'commission' => '-0.5', 'swap' => '0',
                    'time' => gmdate('Y-m-d\TH:i:s.000\Z'),
                    'brokerTime' => gmdate('Y-m-d H:i:s.000', strtotime('+3 hours'))],
                ['id' => 'dev-eur-in-' . $day, 'positionId' => 'dev-eur-' . $day, 'entryType' => 'DEAL_ENTRY_IN',
                    'symbol' => 'EUR/USD', 'type' => 'sell', 'price' => '1.0850', 'volume' => '0.2',
                    'profit' => '0', 'commission' => '0', 'swap' => '0',
                    'time' => gmdate('Y-m-d\TH:i:s.000\Z', strtotime('-2 days')),
                    'brokerTime' => gmdate('Y-m-d H:i:s.000', strtotime('-2 days +3 hours'))],
                ['id' => 'dev-eur-out-' . $day, 'positionId' => 'dev-eur-' . $day, 'entryType' => 'DEAL_ENTRY_OUT',
                    'symbol' => 'EUR/USD', 'type' => 'sell', 'price' => '1.0820', 'volume' => '0.2',
                    'profit' => '60.00', 'commission' => '-0.5', 'swap' => '0',
                    'time' => gmdate('Y-m-d\TH:i:s.000\Z', strtotime('-1 day')),
                    'brokerTime' => gmdate('Y-m-d H:i:s.000', strtotime('-1 day +3 hours'))],
            ];
        }

        $payload = is_string($job['payload'] ?? null) ? json_decode($job['payload'], true) : [];
        $months = max(1, min(12, (int) (($payload['months'] ?? 12))));
        $fromTs = !empty($job['range_from']) ? strtotime((string) $job['range_from']) : strtotime("-{$months} months");
        $minimum = strtotime('-12 months');
        if ($fromTs === false || $fromTs < $minimum) {
            $fromTs = $minimum;
        }
        $from = gmdate('Y-m-d\TH:i:s.000\Z', $fromTs);
        $to = gmdate('Y-m-d\TH:i:s.000\Z');
        $response = $this->providerRequest(
            'GET',
            $this->clientBaseUrl . "/users/current/accounts/{$providerId}/history-deals/time/{$from}/{$to}",
            null,
            [],
            30,
        );
        $this->requireSuccess($response, 'METAAPI_HISTORY_FAILED');
        $rawDeals = is_array($response['json']) ? ($response['json']['deals'] ?? []) : null;
        if (!is_array($rawDeals)) {
            throw new ProviderRequestException('METAAPI_INVALID_RESPONSE', true);
        }
        $deals = [];
        foreach ($rawDeals as $deal) {
            $normalized = is_array($deal) ? $this->normalizeExternalDeal($deal) : null;
            if ($normalized !== null) {
                $deals[] = $normalized;
            }
        }
        return $deals;
    }

    /**
     * Collect normalized deals from a webhook payload. Accepts either a single
     * deal object, a {deal:{...}}, or a {deals:[...]} / {data:[...]} batch.
     *
     * @return array<int,array<string,mixed>>
     */
    private function extractWebhookDeals(array $payload): array
    {
        $candidates = [];
        if (isset($payload['deals']) && is_array($payload['deals'])) {
            $candidates = $payload['deals'];
        } elseif (isset($payload['data']) && is_array($payload['data']) && array_is_list($payload['data'])) {
            $candidates = $payload['data'];
        } else {
            $candidates = [$payload['deal'] ?? $payload['data'] ?? $payload];
        }
        $deals = [];
        foreach ($candidates as $deal) {
            $normalized = is_array($deal) ? $this->normalizeExternalDeal($deal) : null;
            if ($normalized !== null) {
                $deals[] = $normalized;
            }
        }
        return $deals;
    }

    /**
     * Persist one ASSEMBLED closed-position trade, including the canonical
     * timestamp columns. Rows sourced from MetaApi carry:
     *   - occurred_open_at_utc / occurred_close_at_utc = the offset-explicit
     *     deal instants (true UTC), open/close independently resolved;
     *   - time_status='resolved', source_calendar='gregorian',
     *     source_timezone=NULL (no IANA zone exists), and
     *     source_timezone_source='metaapi_instant';
     *   - raw_open_text/raw_close_text = the verbatim absolute `time` strings.
     * Legacy open_time/close_time are set to the SAME genuine UTC instants
     * (they are NOT NULL) — for MetaApi these are real UTC, never a
     * reinterpreted naive wall clock.
     */
    private function insertExternalTrade(PDO $pdo, int $userId, int $accountId, array $trade): int
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $conflict = $driver === 'sqlite'
            ? ' ON CONFLICT(account_id, external_deal_id) DO NOTHING'
            : ' ON DUPLICATE KEY UPDATE updated_at=updated_at';
        $stmt = $pdo->prepare(
            'INSERT INTO trades
             (user_id, account_id, external_deal_id, symbol, direction, entry_price, exit_price,
              volume, commission, swap, profit_loss, open_time, close_time, source,
              occurred_open_at_utc, occurred_close_at_utc, time_status,
              source_timezone, source_timezone_source, source_calendar,
              raw_open_text, raw_close_text, created_at, updated_at)
             VALUES
             (:user_id,:account_id,:external,:symbol,:direction,:entry,:exit,:volume,
              :commission,:swap,:profit,:open_time,:close_time,:source,
              :occurred_open,:occurred_close,:time_status,
              :source_tz,:source_tz_src,:source_calendar,
              :raw_open,:raw_close,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)' . $conflict
        );
        $stmt->execute([
            'user_id' => $userId,
            'account_id' => $accountId,
            'external' => $this->normalizeExternalIdentifier($trade['external_deal_id'] ?? null),
            'symbol' => $trade['symbol'],
            'direction' => $trade['direction'],
            'entry' => $trade['entry_price'],
            'exit' => $trade['exit_price'],
            'volume' => $trade['volume'],
            'commission' => $trade['commission'] ?? '0',
            'swap' => $trade['swap'] ?? '0',
            'profit' => $trade['profit_loss'],
            'open_time' => $trade['open_time'],
            'close_time' => $trade['close_time'],
            'source' => 'auto_sync',
            'occurred_open' => $trade['occurred_open_at_utc'] ?? null,
            'occurred_close' => $trade['occurred_close_at_utc'] ?? null,
            'time_status' => $trade['time_status'] ?? 'unresolved',
            'source_tz' => $trade['source_timezone'] ?? null,
            'source_tz_src' => $trade['source_timezone_source'] ?? MetaApiInstantResolver::PROVENANCE,
            'source_calendar' => $trade['source_calendar'] ?? 'gregorian',
            'raw_open' => $trade['raw_open_text'] ?? null,
            'raw_close' => $trade['raw_close_text'] ?? null,
        ]);
        return $stmt->rowCount() === 1 ? 1 : 0;
    }

    /** @return array{status:int,json:mixed,body:string} */
    private function providerRequest(
        string $method,
        string $url,
        ?array $body,
        array $extraHeaders,
        int $timeout,
    ): array {
        $headers = array_merge([
            'Accept' => 'application/json',
            'auth-token' => $this->apiToken,
        ], $extraHeaders);
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($this->transport !== null) {
            try {
                $result = ($this->transport)($method, $url, $headers, $body, $timeout);
            } catch (\Throwable $e) {
                throw new ProviderRequestException('METAAPI_TRANSPORT_FAILED', true, $e);
            }
            if (!is_array($result) || !isset($result['status'])) {
                throw new ProviderRequestException('METAAPI_INVALID_RESPONSE', true);
            }
            $raw = isset($result['body']) ? (string) $result['body'] : '';
            return [
                'status' => (int) $result['status'],
                'body' => $raw,
                'json' => $raw === '' ? null : json_decode($raw, true),
            ];
        }

        if (!function_exists('curl_init')) {
            throw new ProviderRequestException('METAAPI_CURL_MISSING', false);
        }
        $ch = curl_init($url);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch);
        curl_close($ch);
        if ($raw === false || $curlError !== 0 || $status === 0) {
            throw new ProviderRequestException('METAAPI_TRANSPORT_FAILED', true);
        }
        return [
            'status' => $status,
            'body' => (string) $raw,
            'json' => $raw === '' ? null : json_decode((string) $raw, true),
        ];
    }

    private function requireSuccess(array $response, string $errorCode): void
    {
        $status = (int) $response['status'];
        if ($status >= 200 && $status < 300) {
            return;
        }
        $ambiguous = $status === 408 || $status === 409 || $status === 425 || $status === 429 || $status >= 500;
        throw new ProviderRequestException($errorCode, $ambiguous);
    }
}

/** Internal provider failure classification; response bodies are never exposed. */
final class ProviderRequestException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly bool $ambiguous,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }
}
