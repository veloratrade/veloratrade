<?php

declare(strict_types=1);

namespace Velora\Support;

use Velora\Auth\Role;
use Velora\Core\Exceptions\ApiException;
use Velora\Core\Exceptions\NotFoundException;
use Velora\Core\RateLimiter;
use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Support\SupportCopilotService;
use Velora\Support\SupportRepository;
use Velora\Support\SupportService;
use Velora\Support\SupportTranslationService;

/**
 * Phase 9A — Support Inbox API controller.
 *
 * Authorization is ALWAYS server-side and derived from authenticated context:
 *   - USER routes: session user; ownership enforced at repository level (IDOR-safe).
 *   - ADMIN routes: $admin chain + communication.view / communication.reply.
 * Sender identity (sender_type/sender_user_id) is NEVER taken from the client.
 */
final class SupportController
{
    private SupportService $service;
    private SupportTranslationService $translation;
    private SupportCopilotService $copilot;

    public function __construct(
        ?SupportService $service = null,
        ?SupportTranslationService $translation = null,
        ?SupportCopilotService $copilot = null,
    ) {
        $this->service = $service ?? new SupportService();
        $this->translation = $translation ?? new SupportTranslationService($this->service->repo());
        $this->copilot = $copilot ?? new SupportCopilotService($this->service->repo());
    }

    private function me(Request $r): array
    {
        return [
            'id' => (int) ($r->attributes['user_id'] ?? 0),
            'role' => (string) ($r->attributes['user_role'] ?? 'user'),
        ];
    }

    // ======================= USER (own tickets) =======================

    /** POST /api/v1/support/tickets */
    public function createTicket(Request $r): void
    {
        $me = $this->me($r);
        RateLimiter::hit('support-ticket-user-' . (int) $me['id'], 3, 3600);
        $body = is_array($r->body) ? $r->body : [];
        $res = $this->service->createTicket($me['id'], $body);
        Response::json(['ticket' => ['id' => $res['id']]], 201);
    }

    /** GET /api/v1/support/tickets?status=&page= */
    public function listMyTickets(Request $r): void
    {
        $me = $this->me($r);
        $q = $r->query;
        $page = max(1, (int) ($q['page'] ?? 1));
        $res = $this->service->listUserTickets($me['id'], ['status' => $q['status'] ?? ''], $page);
        Response::json(['tickets' => $res['items'], 'total' => $res['total'], 'page' => $res['page'], 'per_page' => $res['per_page'], 'unread_total' => $this->service->repo()->countersForUser($me['id'])['unread'] ?? 0]);
    }

    /** GET /api/v1/support/tickets/{id} — marks own unread read */
    public function myTicket(Request $r, array $params): void
    {
        $me = $this->me($r);
        $id = (int) ($params['id'] ?? 0);
        $data = $this->service->userTicket($me['id'], $id, true);
        Response::json($data);
    }

    /** POST /api/v1/support/tickets/{id}/messages */
    public function myReply(Request $r, array $params): void
    {
        $me = $this->me($r);
        $id = (int) ($params['id'] ?? 0);
        RateLimiter::hit('support-reply-user-' . $me['id'], 10, 600);
        $body = is_array($r->body) ? $r->body : [];
        $res = $this->service->userReply($me['id'], $id, (string) ($body['message'] ?? ''));
        Response::json(['message' => $res]);
    }

    /** POST /api/v1/support/tickets/{id}/read */
    public function markRead(Request $r, array $params): void
    {
        $me = $this->me($r);
        $id = (int) ($params['id'] ?? 0);
        $this->service->userTicket($me['id'], $id, false); // ownership check
        $this->service->repo()->markUserRead($id);
        Response::json(['ok' => true]);
    }

    /** POST /api/v1/support/tickets/{id}/reopen */
    public function reopen(Request $r, array $params): void
    {
        $me = $this->me($r);
        $id = (int) ($params['id'] ?? 0);
        $res = $this->service->userReopen($me['id'], $id);
        Response::json($res);
    }

    // ======================= ADMIN (communication center) =======================

    /** GET /api/v1/admin/communications/tickets */
    public function adminList(Request $r): void
    {
        $q = $r->query;
        $page = max(1, min(500, (int) ($q['page'] ?? 1)));
        $res = $this->service->adminList($q, $page);
        Response::json($res);
    }

    /** GET /api/v1/admin/communications/tickets/{id} — clears admin unread */
    public function adminTicket(Request $r, array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        Response::json($this->service->adminTicket($id, true));
    }

    /** POST /api/v1/admin/communications/tickets/{id}/messages {message, internal?} */
    public function adminReply(Request $r, array $params): void
    {
        $me = $this->me($r);
        RateLimiter::hit('support-reply-admin', 30, 300);
        $id = (int) ($params['id'] ?? 0);
        $body = is_array($r->body) ? $r->body : [];
        $internal = !empty($body['internal']) && $me['role'] === Role::SUPER_ADMIN;
        $res = $this->service->adminReply($me['id'], $me['role'], $id, (string) ($body['message'] ?? ''), $internal);
        Response::json(['message' => $res]);
    }

    /** POST /api/v1/admin/communications/tickets/{id}/status {action: close|reopen|archive|open} */
    public function adminStatus(Request $r, array $params): void
    {
        $me = $this->me($r);
        $id = (int) ($params['id'] ?? 0);
        $body = is_array($r->body) ? $r->body : [];
        $res = $this->service->adminSetStatus($me['id'], $me['role'], $id, (string) ($body['action'] ?? ''));
        Response::json($res);
    }

    // ======================= AI (admin-only, bounded, non-autonomous) =======================

    /**
     * POST /api/v1/admin/communications/tickets/{id}/translate
     * {message_id}         -> translate a stored message into the ADMIN's language
     * {text, target}       -> translate an outgoing draft preview
     */
    public function translate(Request $r, array $params): void
    {
        $me = $this->me($r);
        RateLimiter::hit('support-ai-admin', 20, 300);
        $body = is_array($r->body) ? $r->body : [];
        try {
            if (isset($body['message_id']) && (int) $body['message_id'] > 0) {
                $target = trim((string) ($body['target'] ?? 'en')) === 'fa' ? 'fa' : 'en';
                $res = $this->translation->translateMessage((int) $body['message_id'], $target);
                Response::json(['translation' => $res]);
                return;
            }
            $target = trim((string) ($body['target'] ?? 'en')) === 'fa' ? 'fa' : 'en';
            $res = $this->translation->translateDraft((string) ($body['text'] ?? ''), $target);
            Response::json(['translation' => $res]);
        } catch (SupportAIException $e) {
            Response::json(['translation' => ['available' => false, 'error' => 'Translation unavailable', 'translated_body' => '', 'provider' => null]], 200);
        }
    }

    /** GET-parameter-free analysis: POST /api/v1/admin/communications/tickets/{id}/copilot */
    public function copilot(Request $r, array $params): void
    {
        $me = $this->me($r);
        RateLimiter::hit('support-copilot-admin', 10, 300);
        $id = (int) ($params['id'] ?? 0);
        $res = $this->copilot->analyze($id);
        Response::json(['copilot' => $res]);
    }

    /** POST /api/v1/admin/communications/tickets/{id}/copilot/draft {draft, instruction?, custom?} */
    public function copilotDraft(Request $r, array $params): void
    {
        $me = $this->me($r);
        RateLimiter::hit('support-copilot-admin', 10, 300);
        $id = (int) ($params['id'] ?? 0);
        if (!$this->service->repo()->conversationExists($id)) {
            throw new NotFoundException('Ticket not found.');
        }
        $body = is_array($r->body) ? $r->body : [];
        $res = $this->copilot->transformDraft((string) ($body['draft'] ?? ''), $id, [
            'instruction' => (string) ($body['instruction'] ?? ''),
            'custom' => (string) ($body['custom'] ?? ''),
        ]);
        Response::json(['draft' => $res]);
    }
}
