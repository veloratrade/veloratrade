<?php

declare(strict_types=1);

namespace Velora\AI\Repositories;

/**
 * Persistence for per-feature ordered provider chains (ai_feature_providers).
 *
 * Validation against ProviderCatalog allowlists happens in the admin
 * controller BEFORE these methods are called; this repository only persists
 * and reads back. An absent table is reported (tableExists=false) so the
 * FeatureRouter can fall back to the environment-driven default chain.
 */
final class AIFeatureProviderRepository extends AIRepository
{
    private const TABLE = 'ai_feature_providers';

    public function tableExists(): bool
    {
        try {
            $pdo = $this->connection();
            $stmt = $pdo->prepare(
                "SELECT name FROM sqlite_master WHERE type='table' AND name = :t"
            );
            $stmt->execute(['t' => self::TABLE]);
            if ($stmt->fetch() !== false) {
                return true;
            }
            $stmt = $pdo->prepare('SHOW TABLES LIKE :t');
            $stmt->execute(['t' => self::TABLE]);
            return $stmt->fetch() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array<int,array<string,mixed>> all rows ordered feature,priority */
    public function all(): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        try {
            return $this->connection()
                ->query('SELECT * FROM ' . self::TABLE . ' ORDER BY feature ASC, priority ASC, id ASC')
                ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Ordered enabled+disabled rows for one feature (priority ASC).
     * The caller filters enabled/capability/credential — the router needs the
     * full picture, the admin panel too.
     *
     * @return array<int,array<string,mixed>>
     */
    public function chainFor(string $feature): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        try {
            $stmt = $this->connection()->prepare(
                'SELECT * FROM ' . self::TABLE . ' WHERE feature = :feature ORDER BY priority ASC, id ASC'
            );
            $stmt->execute(['feature' => $feature]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }
        try {
            $stmt = $this->connection()->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Insert a validated row. Throws on DB failure (caller maps to 5xx).
     *
     * @param array{feature:string,provider:string,model:?string,priority:int,enabled:bool,route:?string} $data
     * @return array<string,mixed> the persisted row (read-back)
     */
    public function insert(array $data): array
    {
        $pdo = $this->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (feature, provider, model, priority, enabled, route)
             VALUES (:feature, :provider, :model, :priority, :enabled, :route)'
        );
        $stmt->execute([
            'feature' => $data['feature'],
            'provider' => $data['provider'],
            'model' => $data['model'],
            'priority' => $data['priority'],
            'enabled' => $data['enabled'] ? 1 : 0,
            'route' => $data['route'],
        ]);
        return $this->find((int) $pdo->lastInsertId());
    }

    /**
     * Update validated fields; returns the read-back row or null when the id
     * does not exist.
     *
     * @param array<string,mixed> $fields subset of model/priority/enabled/route/feature/provider
     * @return array<string,mixed>|null
     */
    public function update(int $id, array $fields): ?array
    {
        $allowed = ['feature', 'provider', 'model', 'priority', 'enabled', 'route'];
        $sets = [];
        $params = ['id' => $id];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "$col = :$col";
                $params[$col] = $col === 'enabled' ? ($fields[$col] ? 1 : 0) : $fields[$col];
            }
        }
        if ($sets === []) {
            return $this->find($id);
        }
        $stmt = $this->connection()->prepare(
            'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        $stmt->execute($params);
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->connection()->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reassign priorities 1..n for a feature following the given id order.
     *
     * @param int[] $orderedIds
     * @return array<int,array<string,mixed>> refreshed rows
     */
    public function reorder(string $feature, array $orderedIds): array
    {
        $pdo = $this->connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE ' . self::TABLE . ' SET priority = :priority WHERE id = :id AND feature = :feature'
            );
            $priority = 1;
            foreach ($orderedIds as $id) {
                $stmt->execute(['priority' => $priority, 'id' => (int) $id, 'feature' => $feature]);
                $priority++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $this->chainFor($feature);
    }

    public function countAll(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }
        try {
            return (int) $this->connection()
                ->query('SELECT COUNT(*) FROM ' . self::TABLE)
                ->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
