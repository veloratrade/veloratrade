<?php

declare(strict_types=1);

namespace Velora\Auth;

use PDO;
use Velora\Core\Database;

/**
 * Data access for the users table.
 * No business logic here — only prepared-statement queries (CTO checklist #1).
 */
final class UserRepository
{
    public function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, email, email_verified_at, password_hash, full_name, role, timezone, locale, locale_source, status, created_at
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, email, email_verified_at, password_hash, full_name, role, timezone, locale, locale_source, status, created_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function emailExists(string $email): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array{email:string, password_hash:string, full_name:string, timezone:string, locale?:string} $data
     */
    public function create(array $data): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (email, password_hash, full_name, timezone, locale)
             VALUES (:email, :password_hash, :full_name, :timezone, :locale)'
        );
        $stmt->execute([
            'email' => $data['email'],
            'password_hash' => $data['password_hash'],
            'full_name' => $data['full_name'],
            'timezone' => $data['timezone'],
            'locale' => $data['locale'] ?? 'fa',
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Persist an explicit language preference (PR-03). $source records how the
     * preference was established (default|browser|cookie|user). Returns false
     * when no row was updated (unknown user id).
     */
    public function updateLocalePreference(int $userId, string $locale, string $source): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users
                SET locale = :locale, locale_source = :source, locale_updated_at = :updated_at
              WHERE id = :id'
        );
        $stmt->execute([
            'locale' => $locale,
            'source' => $source,
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'id' => $userId,
        ]);
        return $stmt->rowCount() > 0;
    }
}
