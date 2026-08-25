<?php

declare(strict_types=1);

namespace Velora\AI\Repositories;

use PDO;
use Velora\Core\Database;

/**
 * Base repository for AI module.
 * Isolates database access for future separate AI database migration.
 * Currently uses Database::connection(), future: Database::aiConnection().
 */
abstract class AIRepository
{
    /**
     * Get database connection — isolated for future migration.
     */
    protected function connection(): PDO
    {
        // Future: return Database::aiConnection();
        return Database::connection();
    }

    /**
     * Helper to bind nullable values.
     */
    protected static function bindNullable(\PDOStatement $stmt, string $param, mixed $value, int $type = \PDO::PARAM_STR): void
    {
        if ($value === null) {
            $stmt->bindValue($param, null, \PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($param, $value, $type);
        }
    }
}
