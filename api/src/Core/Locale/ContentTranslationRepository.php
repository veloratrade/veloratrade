<?php

declare(strict_types=1);

namespace Velora\Core\Locale;

use PDO;
use Velora\Core\Database;

/**
 * Persistent translation cache and queue access.
 *
 * Read methods never call or enqueue a translation provider. Writes are used only
 * by content-ingestion code and the CLI worker, keeping translation work entirely
 * outside request-time rendering.
 */
final class ContentTranslationRepository
{
    private readonly PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public static function fromDatabase(): self
    {
        return new self();
    }

    /**
     * @param array<int,array{contentType:string,contentId:string,sourceHash:string}> $items
     * @return array<int,array<string,mixed>>
     */
    public function lookupReady(string $targetLocale, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $clauses = [];
        $params = ['targetLocale' => $targetLocale];
        foreach (array_values($items) as $index => $item) {
            $clauses[] = "(content_type = :type{$index} AND content_id = :id{$index} AND source_hash = :hash{$index})";
            $params["type{$index}"] = $item['contentType'];
            $params["id{$index}"] = $item['contentId'];
            $params["hash{$index}"] = $item['sourceHash'];
        }

        $sql = 'SELECT content_type, content_id, source_hash, source_locale, target_locale, translated_fields '
            . 'FROM content_translation_cache WHERE status = \'ready\' AND target_locale = :targetLocale AND ('
            . implode(' OR ', $clauses) . ')';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        $translations = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fields = json_decode((string) $row['translated_fields'], true);
            if (!is_array($fields)) {
                continue;
            }
            $translations[] = [
                'contentType' => (string) $row['content_type'],
                'contentId' => (string) $row['content_id'],
                'sourceHash' => (string) $row['source_hash'],
                'sourceLocale' => (string) $row['source_locale'],
                'targetLocale' => (string) $row['target_locale'],
                'fields' => $fields,
            ];
        }
        return $translations;
    }

    /**
     * Called by ingestion/import pipelines, never by the cache lookup endpoint.
     * Duplicate source versions are deduplicated by the database identity key.
     *
     * @param array<string,string|null> $sourceFields
     */
    public function enqueue(
        string $contentType,
        string $contentId,
        string $sourceLocale,
        string $targetLocale,
        string $sourceHash,
        array $sourceFields,
    ): void {
        $fields = json_encode($sourceFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = 'INSERT INTO content_translation_jobs '
                . '(content_type, content_id, source_locale, target_locale, source_hash, source_fields, status, available_at) '
                . "VALUES (:type, :id, :sourceLocale, :targetLocale, :hash, :fields, 'pending', CURRENT_TIMESTAMP) "
                . 'ON CONFLICT(content_type, content_id, source_hash, target_locale) DO NOTHING';
        } else {
            $sql = 'INSERT INTO content_translation_jobs '
                . '(content_type, content_id, source_locale, target_locale, source_hash, source_fields, status, available_at) '
                . "VALUES (:type, :id, :sourceLocale, :targetLocale, :hash, :fields, 'pending', CURRENT_TIMESTAMP) "
                . 'ON DUPLICATE KEY UPDATE id = id';
        }
        $this->pdo->prepare($sql)->execute([
            'type' => $contentType,
            'id' => $contentId,
            'sourceLocale' => $sourceLocale,
            'targetLocale' => $targetLocale,
            'hash' => $sourceHash,
            'fields' => $fields,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function claim(string $workerId): ?array
    {
        $this->recoverStaleLocks();

        // Optimistic claim works on both MySQL and SQLite. If another worker
        // wins the candidate between SELECT and UPDATE, retry with the next row
        // rather than incorrectly treating contention as an empty queue.
        for ($collision = 0; $collision < 20; $collision++) {
            $statement = $this->pdo->query(
                "SELECT * FROM content_translation_jobs
                 WHERE status IN ('pending', 'retry') AND attempts < max_attempts AND available_at <= CURRENT_TIMESTAMP
                 ORDER BY id ASC LIMIT 1"
            );
            $job = $statement->fetch(PDO::FETCH_ASSOC);
            // End the read snapshot before attempting the optimistic UPDATE. This
            // matters on SQLite/WAL, where upgrading an open read snapshot can
            // raise SQLITE_BUSY instead of waiting for a concurrent writer.
            $statement->closeCursor();
            if ($job === false) {
                return null;
            }

            $claim = $this->pdo->prepare(
                "UPDATE content_translation_jobs
                 SET status = 'processing', locked_at = CURRENT_TIMESTAMP, locked_by = :worker,
                     attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND status IN ('pending', 'retry')
                   AND attempts < max_attempts AND available_at <= CURRENT_TIMESTAMP"
            );
            try {
                $claim->execute(['worker' => $workerId, 'id' => $job['id']]);
            } catch (\PDOException $error) {
                if (!self::isTransientContention($error)) {
                    throw $error;
                }
                usleep(min(20000, 1000 * ($collision + 1)));
                continue;
            }
            if ($claim->rowCount() !== 1) {
                continue;
            }

            $job['attempts'] = (int) $job['attempts'] + 1;
            $job['status'] = 'processing';
            $job['locked_by'] = $workerId;
            try {
                $job['source_fields'] = json_decode(
                    (string) $job['source_fields'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
            } catch (\JsonException) {
                $this->fail($job, 'Invalid source_fields JSON.');
                continue;
            }
            return $job;
        }

        return null;
    }

    /** @param array<string,string|null> $translatedFields */
    public function complete(array $job, array $translatedFields, string $provider): void
    {
        $pdo = $this->pdo;
        $pdo->beginTransaction();
        try {
            // Claim ownership is the write fence: a stale worker must never overwrite
            // the cache after another worker has recovered and reclaimed this job.
            $lease = $pdo->prepare(
                "UPDATE content_translation_jobs
                 SET status = 'done', locked_at = NULL, locked_by = NULL, last_error = NULL, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND status = 'processing' AND locked_by = :worker"
            );
            $lease->execute(['id' => $job['id'], 'worker' => $job['locked_by']]);
            if ($lease->rowCount() !== 1) {
                throw new \RuntimeException('Translation job lease was lost.');
            }

            $fields = json_encode($translatedFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $sql = 'INSERT INTO content_translation_cache '
                    . '(content_type, content_id, source_locale, target_locale, source_hash, translated_fields, status, provider) '
                    . "VALUES (:type, :id, :sourceLocale, :targetLocale, :hash, :fields, 'ready', :provider) "
                    . 'ON CONFLICT(content_type, content_id, source_hash, target_locale) DO UPDATE SET '
                    . "translated_fields = excluded.translated_fields, status = 'ready', provider = excluded.provider, updated_at = CURRENT_TIMESTAMP";
            } else {
                $sql = 'INSERT INTO content_translation_cache '
                    . '(content_type, content_id, source_locale, target_locale, source_hash, translated_fields, status, provider) '
                    . "VALUES (:type, :id, :sourceLocale, :targetLocale, :hash, :fields, 'ready', :provider) "
                    . "ON DUPLICATE KEY UPDATE translated_fields = VALUES(translated_fields), status = 'ready', provider = VALUES(provider), updated_at = CURRENT_TIMESTAMP";
            }
            $pdo->prepare($sql)->execute([
                'type' => $job['content_type'],
                'id' => $job['content_id'],
                'sourceLocale' => $job['source_locale'],
                'targetLocale' => $job['target_locale'],
                'hash' => $job['source_hash'],
                'fields' => $fields,
                'provider' => $provider,
            ]);
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function fail(array $job, string $error): void
    {
        $attempts = (int) $job['attempts'];
        $maxAttempts = (int) $job['max_attempts'];
        $failed = $attempts >= $maxAttempts;
        $delaySeconds = min(3600, 30 * (2 ** max(0, $attempts - 1)));
        $availableAt = gmdate('Y-m-d H:i:s', time() + $delaySeconds);
        $statement = $this->pdo->prepare(
            "UPDATE content_translation_jobs SET status = :status, available_at = :availableAt,
             locked_at = NULL, locked_by = NULL, last_error = :error, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'processing' AND locked_by = :worker"
        );
        $statement->execute([
            'status' => $failed ? 'failed' : 'retry',
            'availableAt' => $availableAt,
            'error' => substr($error, 0, 2000),
            'id' => $job['id'],
            'worker' => $job['locked_by'],
        ]);
    }

    private static function isTransientContention(\PDOException $error): bool
    {
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        $sqlState = (string) ($error->errorInfo[0] ?? $error->getCode());
        return in_array($driverCode, [5, 6, 1205, 1213], true)
            || in_array($sqlState, ['40001', '40P01'], true);
    }

    private function recoverStaleLocks(): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 900);
        $statement = $this->pdo->prepare(
            "UPDATE content_translation_jobs
             SET status = CASE WHEN attempts >= max_attempts THEN 'failed' ELSE 'retry' END,
                 available_at = CURRENT_TIMESTAMP, locked_at = NULL, locked_by = NULL,
                 last_error = CASE WHEN attempts >= max_attempts THEN 'Worker lease expired after final attempt.' ELSE last_error END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE status = 'processing' AND locked_at < :cutoff"
        );
        $statement->execute(['cutoff' => $cutoff]);
    }
}
