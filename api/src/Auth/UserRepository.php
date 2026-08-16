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
            'SELECT id, email, email_verified_at, password_hash, full_name, role, timezone, status, created_at
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, email, email_verified_at, password_hash, full_name, role, timezone, status, created_at
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
     * @param array{email:string, password_hash:string, full_name:string, timezone:string} $data
     */
    public function create(array $data): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (email, password_hash, full_name, timezone)
             VALUES (:email, :password_hash, :full_name, :timezone)'
        );
        $stmt->execute([
            'email' => $data['email'],
            'password_hash' => $data['password_hash'],
            'full_name' => $data['full_name'],
            'timezone' => $data['timezone'],
        ]);
        return (int) Database::connection()->lastInsertId();
    }
}
