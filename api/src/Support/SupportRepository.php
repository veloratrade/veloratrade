<?php

declare(strict_types=1);

namespace Velora\Support;

use PDO;
use Velora\Core\Database;

/**
 * Phase 9A — Support Inbox repository (PDO, prepared statements only).
 *
 * Owns persistence for support_conversations / support_messages /
 * support_message_translations. All filters are whitelisted by the service
 * layer; ordering is deterministic; pagination is bounded.
 */
final class SupportRepository
{
    private const STATUSES = ['open', 'pending', 'closed', 'archived'];
    private const WAITING = ['admin', 'user', 'none'];

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    public static function connect(): self
    {
        return new self(Database::connection());
    }

    public function pdo(): PDO
    {
        return $this->db();
    }

    /** @param array<string,mixed> $conversation @param array<string,mixed> $message */
    public function createTicket(array $conversation, array $message): int
    {
        $this->db()->beginTransaction();
        try {
            $now = gmdate('Y-m-d H:i:s');
            $st = $this->db()->prepare(
                'INSERT INTO support_conversations (user_id, subject, status, waiting_for, priority, last_message_at, unread_admin_count, unread_user_count, created_at, updated_at)
                 VALUES (:u, :s, :st, :wf, :pr, :lm, 1, 0, :c, :c)'
            );
            $st->execute([
                ':u' => $conversation['user_id'],
                ':s' => $conversation['subject'],
                ':st' => $conversation['status'] ?? 'open',
                ':wf' => $conversation['waiting_for'] ?? 'admin',
                ':pr' => $conversation['priority'] ?? null,
                ':lm' => $now,
                ':c' => $now,
            ]);
            $cid = (int) $this->db()->lastInsertId();

            $mi = $this->db()->prepare(
                'INSERT INTO support_messages (conversation_id, sender_type, sender_user_id, body, message_type, created_at)
                 VALUES (:c, :t, :u, :b, :mt, :n)'
            );
            $mi->execute([
                ':c' => $cid,
                ':t' => $message['sender_type'],
                ':u' => $message['sender_user_id'],
                ':b' => $message['body'],
                ':mt' => $message['message_type'] ?? 'text',
                ':n' => $now,
            ]);
            $this->db()->commit();
            return $cid;
        } catch (\Throwable $e) {
            $this->db()->rollBack();
            throw $e;
        }
    }

    public function conversationExists(int $id): bool
    {
        $st = $this->db()->prepare('SELECT 1 FROM support_conversations WHERE id = :id LIMIT 1');
        $st->execute([':id' => $id]);
        return $st->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    public function conversation(int $id): ?array
    {
        $st = $this->db()->prepare(
            'SELECT c.*, u.email AS user_email, u.full_name AS user_name, u.locale AS user_locale, u.status AS user_status
             FROM support_conversations c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.id = :id LIMIT 1'
        );
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Ownership-scoped conversation fetch for users (IDOR-safe). */
    public function conversationForUser(int $id, int $userId): ?array
    {
        $st = $this->db()->prepare(
            'SELECT c.* FROM support_conversations c WHERE c.id = :id AND c.user_id = :u LIMIT 1'
        );
        $st->execute([':id' => $id, ':u' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Deterministic, bounded listing.
     * @param array{status?:string,waiting_for?:string,unread?:string,user_id?:int,q?:string,limit?:int,offset?:int} $f
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function listConversations(array $f, bool $adminScope = true): array
    {
        $where = [];
        $args = [];
        if (!$adminScope) {
            $where[] = 'c.user_id = :uid0';
            $args[':uid0'] = (int) ($f['user_id'] ?? 0);
        } elseif (isset($f['user_id'])) {
            $where[] = 'c.user_id = :uid';
            $args[':uid'] = (int) $f['user_id'];
        }
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $where[] = 'c.status = :st';
            $args[':st'] = (string) $f['status'];
        }
        if (!empty($f['waiting_for']) && in_array($f['waiting_for'], self::WAITING, true)) {
            $where[] = 'c.waiting_for = :wf';
            $args[':wf'] = (string) $f['waiting_for'];
        }
        if (($f['unread'] ?? '') === 'admin') {
            $where[] = 'c.unread_admin_count > 0';
        } elseif (($f['unread'] ?? '') === 'user') {
            $where[] = 'c.unread_user_count > 0';
        }
        if (!empty($f['q'])) {
            $where[] = '(c.subject LIKE :q OR c.id = :qid OR u.email LIKE :q)';
            $args[':q'] = '%' . $f['q'] . '%';
            $args[':qid'] = (int) $f['q'];
        }
        $whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

        $limit = min(50, max(1, (int) ($f['limit'] ?? 20)));
        $offset = max(0, (int) ($f['offset'] ?? 0));

        $cst = $this->db()->prepare("SELECT COUNT(*) FROM support_conversations c LEFT JOIN users u ON u.id = c.user_id $whereSql");
        $cst->execute($args);
        $total = (int) $cst->fetchColumn();

        $q = "SELECT c.id, c.user_id, c.subject, c.status, c.waiting_for, c.priority, c.last_message_at,
                     c.unread_admin_count, c.unread_user_count, c.created_at, c.updated_at,
                     u.email AS user_email, u.full_name AS user_name, u.locale AS user_locale
              FROM support_conversations c LEFT JOIN users u ON u.id = c.user_id
              $whereSql
              ORDER BY c.last_message_at DESC, c.id DESC
              LIMIT $limit OFFSET $offset";
        $st = $this->db()->prepare($q);
        $st->execute($args);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public function messages(int $conversationId, int $limit = 100, int $beforeId = 0, bool $includeInternal = true): array
    {
        $limit = min(200, max(1, $limit));
        $internal = $includeInternal ? '' : " AND message_type <> 'system_note'";
        $st = $this->db()->prepare(
            "SELECT id, conversation_id, sender_type, sender_user_id, body, message_type, metadata_json, created_at, edited_at, deleted_at
             FROM support_messages
             WHERE conversation_id = :c AND deleted_at IS NULL$internal
             ORDER BY id ASC
             LIMIT $limit"
        );
        $st->execute([':c' => $conversationId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function message(int $messageId): ?array
    {
        $st = $this->db()->prepare('SELECT * FROM support_messages WHERE id = :id LIMIT 1');
        $st->execute([':id' => $messageId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Add a reply + update conversation state atomically.
     * @param array{sender_type:string,sender_user_id:?int,body:string,message_type?:string,metadata_json?:?string} $message
     * @return array<string,mixed> inserted message row fields
     */
    public function addMessage(int $conversationId, array $message, string $newStatus, string $newWaiting): array
    {
        $this->db()->beginTransaction();
        try {
            $now = gmdate('Y-m-d H:i:s');
            $st = $this->db()->prepare(
                'INSERT INTO support_messages (conversation_id, sender_type, sender_user_id, body, message_type, metadata_json, created_at)
                 VALUES (:c, :t, :u, :b, :mt, :md, :n)'
            );
            $st->execute([
                ':c' => $conversationId,
                ':t' => $message['sender_type'],
                ':u' => $message['sender_user_id'],
                ':b' => $message['body'],
                ':mt' => $message['message_type'] ?? 'text',
                ':md' => $message['metadata_json'] ?? null,
                ':n' => $now,
            ]);
            $mid = (int) $this->db()->lastInsertId();

            if ($message['sender_type'] === 'admin') {
                $upd = 'UPDATE support_conversations SET status = :s, waiting_for = :w, last_message_at = :n,
                        unread_admin_count = 0, unread_user_count = unread_user_count + 1,
                        first_reply_at = CASE WHEN first_reply_at IS NULL THEN :n2 ELSE first_reply_at END,
                        updated_at = :n3 WHERE id = :id';
                $this->db()->prepare($upd)->execute([
                    ':s' => $newStatus, ':w' => $newWaiting, ':n' => $now, ':n2' => $now, ':n3' => $now, ':id' => $conversationId,
                ]);
            } elseif ($message['sender_type'] === 'user') {
                $upd = 'UPDATE support_conversations SET status = :s, waiting_for = :w, last_message_at = :n,
                        unread_user_count = 0, unread_admin_count = unread_admin_count + 1, updated_at = :n3 WHERE id = :id';
                $this->db()->prepare($upd)->execute([
                    ':s' => $newStatus, ':w' => $newWaiting, ':n' => $now, ':n3' => $now, ':id' => $conversationId,
                ]);
            } else { // system
                $this->db()->prepare('UPDATE support_conversations SET status = :s, waiting_for = :w, updated_at = :n WHERE id = :id')
                    ->execute([':s' => $newStatus, ':w' => $newWaiting, ':n' => $now, ':id' => $conversationId]);
            }
            $this->db()->commit();
            return ['id' => $mid, 'created_at' => $now];
        } catch (\Throwable $e) {
            $this->db()->rollBack();
            throw $e;
        }
    }

    /** Whether this conversation has any prior admin text reply (first-reply email gate). */
    public function hasAdminReply(int $conversationId): bool
    {
        $st = $this->db()->prepare(
            "SELECT COUNT(*) FROM support_messages WHERE conversation_id = :c AND sender_type = 'admin' AND message_type = 'text' AND deleted_at IS NULL"
        );
        $st->execute([':c' => $conversationId]);
        return (int) $st->fetchColumn() > 0;
    }

    /** Atomic status/waiting transition with expected-state guard (race-safe). */
    public function transition(int $conversationId, string $newStatus, string $newWaiting, ?string $expectedStatus = null): bool
    {
        $sql = 'UPDATE support_conversations SET status = :s, waiting_for = :w, updated_at = :n WHERE id = :id';
        $args = [':s' => $newStatus, ':w' => $newWaiting, ':n' => gmdate('Y-m-d H:i:s'), ':id' => $conversationId];
        if ($expectedStatus !== null) {
            $sql .= ' AND status = :es';
            $args[':es'] = $expectedStatus;
        }
        $st = $this->db()->prepare($sql);
        $affected = $st->execute($args) ? $st->rowCount() : 0;
        return (int) $affected > 0;
    }

    public function markAdminRead(int $conversationId): void
    {
        $this->db()->prepare('UPDATE support_conversations SET unread_admin_count = 0, updated_at = :n WHERE id = :id AND unread_admin_count > 0')
            ->execute([':n' => gmdate('Y-m-d H:i:s'), ':id' => $conversationId]);
    }

    public function markUserRead(int $conversationId): void
    {
        $this->db()->prepare('UPDATE support_conversations SET unread_user_count = 0, updated_at = :n WHERE id = :id AND unread_user_count > 0')
            ->execute([':n' => gmdate('Y-m-d H:i:s'), ':id' => $conversationId]);
    }

    /** Aggregate counters for badges / User360. */
    public function countersForUser(int $userId): array
    {
        $st = $this->db()->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status IN ('open','pending') THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed,
                    COALESCE(SUM(unread_user_count), 0) AS unread
             FROM support_conversations WHERE user_id = :u"
        );
        $st->execute([':u' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn ($v) => (int) ($v ?? 0), $row);
    }

    public function countersForAdmin(): array
    {
        $st = $this->db()->query(
            "SELECT SUM(CASE WHEN waiting_for = 'admin' AND status <> 'closed' THEN 1 ELSE 0 END) AS inbox,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed
             FROM support_conversations"
        );
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn ($v) => (int) ($v ?? 0), $row);
    }

    /** User360 support summary (bounded): counters + 3 most recent threads (no bodies). */
    public function userSummary(int $userId): array
    {
        $counters = $this->countersForUser($userId);
        $st = $this->db()->prepare(
            'SELECT id, subject, status, waiting_for, last_message_at, unread_admin_count
             FROM support_conversations WHERE user_id = :u ORDER BY last_message_at DESC, id DESC LIMIT 3'
        );
        $st->execute([':u' => $userId]);
        return ['counters' => $counters, 'recent' => $st->fetchAll(PDO::FETCH_ASSOC)];
    }

    // ---------- translations ----------

    public function findTranslation(int $messageId, string $from, string $to): ?array
    {
        $st = $this->db()->prepare(
            'SELECT translated_body, provider, model, created_at FROM support_message_translations
             WHERE message_id = :m AND source_language = :f AND target_language = :t LIMIT 1'
        );
        $st->execute([':m' => $messageId, ':f' => $from, ':t' => $to]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function saveTranslation(int $messageId, string $from, string $to, string $body, string $provider, ?string $model): void
    {
        // Portable upsert (delete + insert; MySQL + sqlite safe):
        $this->db()->prepare(
            'DELETE FROM support_message_translations WHERE message_id = :m AND source_language = :f AND target_language = :t'
        )->execute([':m' => $messageId, ':f' => $from, ':t' => $to]);
        $this->db()->prepare(
            'INSERT INTO support_message_translations (message_id, source_language, target_language, translated_body, provider, model)
             VALUES (:m, :f, :t, :b, :p, :mo)'
        )->execute([':m' => $messageId, ':f' => $from, ':t' => $to, ':b' => $body, ':p' => $provider, ':mo' => $model]);
    }
}
