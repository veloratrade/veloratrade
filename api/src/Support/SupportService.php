<?php

declare(strict_types=1);

namespace Velora\Support;

use Velora\Admin\AdminAuditLogRepository;
use Velora\Core\Exceptions\ApiException;

/**
 * Phase 9A — Support ticket domain service.
 *
 * Lifecycle (status + waiting_for are INDEPENDENT axes; never client-trusted):
 *   user creates            -> status=open,     waiting_for=admin   (+ NEW_TICKET email to support)
 *   admin replies (text)    -> status=pending,  waiting_for=user    (+ FIRST_ADMIN_REPLY email once)
 *   user replies            -> status=open,     waiting_for=admin
 *   admin closes            -> status=closed,   waiting_for=none
 *   user reopens            -> status=open,     waiting_for=admin
 *   admin reopens           -> status=pending,  waiting_for=user
 *
 * Email events are EVENT-BASED and idempotent (first_reply_at sentinel),
 * fire strictly AFTER commit, and never roll back message persistence.
 */
final class SupportService
{
    public const OPEN = 'open';
    public const PENDING = 'pending';
    public const CLOSED = 'closed';
    public const ARCHIVED = 'archived';

    public const WF_ADMIN = 'admin';
    public const WF_USER = 'user';
    public const WF_NONE = 'none';

    private const MAX_SUBJECT = 200;
    private const MAX_BODY = 5000;

    public function __construct(
        private readonly SupportRepository $repo = new SupportRepository(),
        private readonly AdminAuditLogRepository $audit = new AdminAuditLogRepository(),
    ) {
    }

    public function repo(): SupportRepository
    {
        return $this->repo;
    }

    // ---------- user side ----------

    /** @return array{id:int} */
    public function createTicket(int $userId, array $body): array
    {
        $subject = trim((string) ($body['subject'] ?? ''));
        $text = trim((string) ($body['message'] ?? ''));
        if ($subject === '' || mb_strlen($subject) > self::MAX_SUBJECT) {
            throw new ApiException('Subject is required (max ' . self::MAX_SUBJECT . ' chars).', 422, 'SUPPORT_SUBJECT_INVALID', null, 'errors.support.subjectInvalid');
        }
        if ($text === '' || mb_strlen($text) > self::MAX_BODY) {
            throw new ApiException('Message is required (max ' . self::MAX_BODY . ' chars).', 422, 'SUPPORT_MESSAGE_INVALID', null, 'errors.support.messageInvalid');
        }
        // strip control chars / normalize line boundaries; body stored raw (escaped at render time)
        $subject = self::sanitizeText($subject);
        $text = self::sanitizeText($text);

        $conversationId = $this->repo->createTicket(
            ['user_id' => $userId, 'subject' => $subject, 'status' => self::OPEN, 'waiting_for' => self::WF_ADMIN],
            ['sender_type' => 'user', 'sender_user_id' => $userId, 'body' => $text]
        );

        // Post-commit event: NEW_TICKET (admin notification). Failure never invalidates the ticket.
        $this->notifyNewTicket($conversationId);

        $this->audit->record($userId, 'user', 'support.ticket_created', 'conversation', $conversationId, 'success', null, null, null, null, ['conversationId' => $conversationId]);
        return ['id' => $conversationId];
    }

    public function listUserTickets(int $userId, array $filters, int $page): array
    {
        $filters['user_id'] = $userId;
        $f = $this->boundedFilters($filters, false);
        $res = $this->repo->listConversations($f, false);
        $res['page'] = $f['offset'] !== 0 ? intdiv((int) $f['offset'], 20) + 1 : 1;
        $res['per_page'] = 20;
        return $res;
    }

    /** Ownership-enforced detail (IDOR-safe): throws NotFoundException for foreign tickets. */
    public function userTicket(int $userId, int $conversationId, bool $markRead = false): array
    {
        $conv = $this->repo->conversationForUser($conversationId, $userId);
        if ($conv === null) {
            throw new \Velora\Core\Exceptions\NotFoundException('Ticket not found.');
        }
        $messages = $this->repo->messages($conversationId, 200, 0, false); // system notes are never user-visible
        if ($markRead) {
            $this->repo->markUserRead($conversationId);
        }
        return ['conversation' => $conv, 'messages' => $messages];
    }

    public function userReply(int $userId, int $conversationId, string $text): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_BODY) {
            throw new ApiException('Message is required (max ' . self::MAX_BODY . ' chars).', 422, 'SUPPORT_MESSAGE_INVALID', null, 'errors.support.messageInvalid');
        }
        $conv = $this->repo->conversationForUser($conversationId, $userId);
        if ($conv === null) {
            throw new \Velora\Core\Exceptions\NotFoundException('Ticket not found.');
        }
        if ($conv['status'] === self::CLOSED || $conv['status'] === self::ARCHIVED) {
            throw new ApiException('Ticket is closed; reopen it first.', 422, 'SUPPORT_TICKET_CLOSED', null, 'errors.support.ticketClosed');
        }
        $text = self::sanitizeText($text);
        return $this->insertReply($conv, 'user', $userId, $text, self::OPEN, self::WF_ADMIN);
    }

    public function userReopen(int $userId, int $conversationId): array
    {
        $conv = $this->repo->conversationForUser($conversationId, $userId);
        if ($conv === null) {
            throw new \Velora\Core\Exceptions\NotFoundException('Ticket not found.');
        }
        return $this->doReopen($conv, 'user', $userId);
    }

    // ---------- admin side ----------

    public function adminList(array $filters, int $page): array
    {
        $f = $this->boundedFilters($filters, true);
        $f['offset'] = ($page - 1) * 20;
        $res = $this->repo->listConversations($f, true);
        $res['page'] = $page;
        $res['per_page'] = 20;
        $res['counters'] = $this->repo->countersForAdmin();
        return $res;
    }

    /** Admin detail; clears the admin unread badge. */
    public function adminTicket(int $conversationId, bool $markRead = true): array
    {
        $conv = $this->repo->conversation($conversationId);
        if ($conv === null) {
            throw new \Velora\Core\Exceptions\NotFoundException('Ticket not found.');
        }
        $messages = $this->repo->messages($conversationId, 200);
        if ($markRead) {
            $this->repo->markAdminRead($conversationId);
            $conv['unread_admin_count'] = 0;
        }
        return ['conversation' => $conv, 'messages' => $messages];
    }

    public function adminReply(int $actorId, string $actorRole, int $conversationId, string $text, bool $isInternalNote = false): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_BODY) {
            throw new ApiException('Message is required (max ' . self::MAX_BODY . ' chars).', 422, 'SUPPORT_MESSAGE_INVALID', null, 'errors.support.messageInvalid');
        }
        $conv = $this->repo->conversation($conversationId);
        if ($conv === null) {
            throw new \Velora\Core\Exceptions\NotFoundException('Ticket not found.');
        }
        $text = self::sanitizeText($text);
        $type = $isInternalNote ? 'system_note' : 'text';
        $res = $this->insertReply($conv, 'admin', $actorId, $text, self::PENDING, self::WF_USER, $type);
        $this->audit->record($actorId, $actorRole, $isInternalNote ? 'support.note_added' : 'support.replied', 'conversation', $conversationId, 'success', null, null, null, null, ['conversationId' => $conversationId, 'messageId' => $res['id'], 'internal' => $isInternalNote ? 1 : 0]);
        return $res;
    }

    public function adminSetStatus(int $actorId, string $actorRole, int $conversationId, string $action): array
    {
        $conv = $this->repo->conversation($conversationId);
        if ($conv === null) {
            throw new \Velora\Core\Exceptions\NotFoundException('Ticket not found.');
        }
        $from = (string) $conv['status'];
        switch ($action) {
            case 'close':
                $ok = $this->repo->transition($conversationId, self::CLOSED, self::WF_NONE);
                $event = 'support.closed';
                break;
            case 'reopen':
                $res = $this->doReopen($conv, 'admin', $actorId, $actorRole);
                return $res;
            case 'archive':
                if ($from !== self::CLOSED) {
                    throw new ApiException('Only closed tickets can be archived.', 422, 'SUPPORT_INVALID_TRANSITION', null, 'errors.support.invalidTransition');
                }
                $ok = $this->repo->transition($conversationId, self::ARCHIVED, self::WF_NONE);
                $event = 'support.archived';
                break;
            case 'open':
                $ok = $this->repo->transition($conversationId, self::OPEN, self::WF_ADMIN);
                $event = 'support.reopened';
                break;
            default:
                throw new ApiException('Unknown status action.', 422, 'SUPPORT_INVALID_ACTION', null, 'errors.support.invalidAction');
        }
        if (!$ok) {
            throw new \Velora\Core\Exceptions\ConflictException('Ticket state changed concurrently; reload and retry.');
        }
        $this->audit->record($actorId, $actorRole, $event, 'conversation', $conversationId, 'success', null, null, null, null, ['conversationId' => $conversationId, 'from' => $from, 'to' => $action === 'close' ? self::CLOSED : ($action === 'archive' ? self::ARCHIVED : self::OPEN)]);
        return ['status' => $action === 'close' ? self::CLOSED : ($action === 'archive' ? self::ARCHIVED : self::OPEN)];
    }

    // ---------- shared internals ----------

    /** Insert a reply with lifecycle rules; fires FIRST_ADMIN_REPLY exactly once. */
    private function insertReply(array $conv, string $senderType, ?int $senderId, string $text, string $newStatus, string $newWaiting, string $messageType = 'text'): array
    {
        $isFirstAdminReply = $senderType === 'admin' && $messageType === 'text'
            && $conv['first_reply_at'] === null
            && !$this->repo->hasAdminReply((int) $conv['id']);

        $res = $this->repo->addMessage((int) $conv['id'], [
            'sender_type' => $senderType,
            'sender_user_id' => $senderId,
            'body' => $text,
            'message_type' => $messageType,
        ], $newStatus, $newWaiting);

        // Post-commit, idempotent (first_reply_at now set atomically inside addMessage).
        if ($isFirstAdminReply) {
            $this->notifyFirstAdminReply((int) $conv['id']);
        }
        return $res;
    }

    private function doReopen(array $conv, string $actor, int $actorId, string $actorRole = 'user'): array
    {
        $from = (string) $conv['status'];
        if ($from !== self::CLOSED && $from !== self::ARCHIVED) {
            throw new ApiException('Only closed tickets can be reopened.', 422, 'SUPPORT_INVALID_TRANSITION', null, 'errors.support.invalidTransition');
        }
        // Actor-dependent transition, explicit and validated server-side:
        // user reopen -> open/admin ; admin reopen -> pending/user
        $newStatus = $actor === 'admin' ? self::PENDING : self::OPEN;
        $newWaiting = $actor === 'admin' ? self::WF_USER : self::WF_ADMIN;
        $ok = $this->repo->transition((int) $conv['id'], $newStatus, $newWaiting);
        if (!$ok) {
            throw new \Velora\Core\Exceptions\ConflictException('Ticket state changed concurrently; reload and retry.');
        }
        $this->audit->record($actorId, $actorRole, 'support.reopened', 'conversation', (int) $conv['id'], 'success', null, null, null, null, ['conversationId' => (int) $conv['id'], 'from' => $from, 'to' => $newStatus, 'waitingFor' => $newWaiting]);
        return ['status' => $newStatus, 'waiting_for' => $newWaiting];
    }

    /** Whitelisted filters + bounded pagination (deterministic). */
    private function boundedFilters(array $raw, bool $adminScope): array
    {
        $f = [];
        if ($adminScope) {
            if (!empty($raw['status'])) {
                $f['status'] = (string) $raw['status'];
            }
            if (!empty($raw['waiting_for'])) {
                $f['waiting_for'] = (string) $raw['waiting_for'];
            }
            if (!empty($raw['unread']) && in_array($raw['unread'], ['admin', 'user'], true)) {
                $f['unread'] = (string) $raw['unread'];
            }
            if (isset($raw['user_id']) && (int) $raw['user_id'] > 0) {
                $f['user_id'] = (int) $raw['user_id'];
            }
            if (!empty($raw['q'])) {
                $f['q'] = mb_substr(trim((string) $raw['q']), 0, 100);
            }
            $f['offset'] = max(0, (int) ($raw['offset'] ?? 0));
        } else {
            $f['user_id'] = (int) ($raw['user_id'] ?? 0); // ownership scope (server-derived)
            if (!empty($raw['status']) && in_array($raw['status'], [self::OPEN, self::PENDING, self::CLOSED, self::ARCHIVED], true)) {
                $f['status'] = (string) $raw['status'];
            }
            $f['offset'] = max(0, (int) ($raw['offset'] ?? 0));
        }
        return $f;
    }

    /** Strip control characters (keep \n); bounded. Body rendered escaped client-side. */
    private static function sanitizeText(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        return trim($s);
    }

    // ---------- email events (reuse the existing NotificationService stack) ----------

    private function notifyNewTicket(int $conversationId): void
    {
        try {
            $conv = $this->repo->conversation($conversationId);
            if ($conv === null) {
                return;
            }
            $preview = mb_substr((string) ($this->repo->messages($conversationId, 1)[0]['body'] ?? ''), 0, 220);
            \Velora\Core\NotificationService::sendSupportNewTicketEmail(
                (int) $conv['user_id'],
                $conversationId,
                (string) $conv['subject'],
                (string) ($conv['user_name'] ?: $conv['user_email']),
                (string) $conv['user_email'],
                (string) $conv['user_locale'],
                $preview
            );
        } catch (\Throwable $e) {
            error_log('[VELORA_SUPPORT_EMAIL_SKIP] new_ticket cid=' . $conversationId . ' err=' . $e->getMessage());
        }
    }

    private function notifyFirstAdminReply(int $conversationId): void
    {
        try {
            $conv = $this->repo->conversation($conversationId);
            if ($conv === null) {
                return;
            }
            $last = $this->repo->messages($conversationId, 200);
            $lastAdmin = null;
            foreach ($last as $m) {
                if ($m['sender_type'] === 'admin' && $m['message_type'] === 'text') {
                    $lastAdmin = $m;
                }
            }
            $preview = mb_substr((string) ($lastAdmin['body'] ?? ''), 0, 220);
            \Velora\Core\NotificationService::sendSupportReplyEmail(
                (int) $conv['user_id'],
                $conversationId,
                (string) $conv['subject'],
                (string) ($conv['user_name'] ?: $conv['user_email']),
                (string) $conv['user_email'],
                (string) $conv['user_locale'],
                $preview
            );
        } catch (\Throwable $e) {
            error_log('[VELORA_SUPPORT_EMAIL_SKIP] first_reply cid=' . $conversationId . ' err=' . $e->getMessage());
        }
    }
}
