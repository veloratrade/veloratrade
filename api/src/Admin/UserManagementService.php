<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Config;
use Velora\Core\Database;
use Velora\Core\Exceptions\ConflictException;
use Velora\Core\Exceptions\ForbiddenException;
use Velora\Core\Exceptions\NotFoundException;
use Velora\Core\Exceptions\ValidationException;
use Velora\Auth\AuthEventRepository;
use Velora\Auth\EmailVerificationRepository;
use Velora\Auth\SessionRepository;
use Velora\Auth\UserDeviceRepository;
use Velora\Auth\PasswordService;
use Velora\Auth\Role;
use Velora\Auth\UserRepository;

/**
 * Professional User Management (Module B).
 *
 * All data operations are read against the real schema. The service NEVER
 * returns credentials/secrets. Because users.role's allowed values and the
 * v1.3 subscription columns are added by an additive migration, the service is
 * schema-tolerant: if the v1.3 columns are absent (env not migrated), it falls
 * back to the base user projection and reports the subscription layer as
 * unavailable rather than failing.
 *
 * Authorization that depends on the TARGET (e.g. an admin must not be allowed
 * to suspend another admin) lives here and is enforced server-side regardless
 * of what the frontend shows.
 */
final class UserManagementService
{
    private const SORTABLE = ['created_at', 'email', 'full_name', 'role', 'status'];

    /** Invite acceptance window (24h) — same token store as password resets. */
    private const INVITE_TTL_SEC = 86400;

    /**
     * Admin Create User (real users-table insert; no mock data).
     *
     * Authorization is SERVER-SIDE and derived from the canonical permission
     * model, never from the client:
     *   - the route already requires `users.create` (admin + super_admin);
     *   - creating a PRIVILEGED account (admin/super_admin) additionally
     *     requires `users.change_role` (Super Admin only) — the same
     *     privilege-escalation rule as setRole();
     *   - plan=pro additionally requires `users.manage_subscription`;
     *   - status is always created 'active' (suspension is a separate,
     *     audited action — not settable at creation).
     *
     * Email verification follows the existing public-registration
     * architecture: the account starts UNVERIFIED and a one-time,
     * expiring verification token is stored hashed + emailed. The token is
     * NEVER returned or audited; the caller reports the honest delivery
     * state (emailSent) coming from NotificationService.
     */
    public function createUser(array $data, int $actorId, string $actorRole): array
    {
        // ---- role (authorization first — never trust the client) ----
        $role = strtolower(trim((string) ($data['role'] ?? Role::USER)));
        if ($role === '') {
            $role = Role::USER;
        }
        if (!Role::isValidStored($role)) {
            throw new ValidationException('Invalid role.', ['role' => ['code' => 'INVALID_CHOICE', 'messageKey' => 'errors.validation.choice', 'params' => []]]);
        }
        if (Role::isPrivileged($role) && !Role::can($actorRole, Role::P_USERS_CHANGE_ROLE)) {
            throw new ForbiddenException('Only Super Admin may create admin-level accounts.', 'PRIVILEGE_ESCALATION_DENIED');
        }

        // ---- plan (RBAC-neutral, but its assignment is a managed action) ----
        $plan = strtolower(trim((string) ($data['plan'] ?? 'free')));
        if ($plan === '') {
            $plan = 'free';
        }
        if (!in_array($plan, ['free', 'pro'], true)) {
            throw new ValidationException('Invalid plan.', ['plan' => ['code' => 'INVALID_PLAN']]);
        }
        if ($plan === 'pro' && !Role::can($actorRole, Role::P_USERS_MANAGE_SUBSCRIPTION)) {
            throw new ForbiddenException('Assigning a paid plan requires subscription management permission.', 'SUBSCRIPTION_MANAGE_DENIED');
        }

        // ---- identity fields ----
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $fullName = trim((string) ($data['fullName'] ?? ($data['full_name'] ?? '')));
        if ($fullName === '') {
            throw new ValidationException('Full name is required.', ['fullName' => ['code' => 'REQUIRED', 'messageKey' => 'errors.validation.required', 'params' => []]]);
        }
        $timezone = trim((string) ($data['timezone'] ?? '')) ?: 'UTC';
        if (mb_strlen($timezone) > 64) {
            throw new ValidationException('Invalid timezone.', ['timezone' => ['code' => 'INVALID_TIMEZONE']]);
        }
        $localeInput = strtolower(trim((string) ($data['locale'] ?? '')));
        $locale = 'fa';
        if ($localeInput !== '') {
            $i18n = \Velora\Core\Locale\LocaleManager::getInstance();
            if (!$i18n->supports($localeInput)) {
                throw new ValidationException('Unsupported locale.', ['locale' => ['code' => 'INVALID_LOCALE']]);
            }
            $locale = $i18n->resolve($localeInput);
        }

        // ---- duplicate email (distinct, actionable codes) ----
        $users = new UserRepository();
        if ($users->emailExists($email)) {
            $existing = $users->findByEmail($email);
            if ($existing !== null && $existing['email_verified_at'] !== null) {
                throw new ConflictException('Email already registered.', 'EMAIL_ALREADY_REGISTERED', 'errors.auth.emailAlreadyRegistered');
            }
            throw new ConflictException('A user with this email is pending email verification.', 'EMAIL_PENDING_VERIFICATION', 'errors.auth.emailPendingVerification');
        }

        // ---- password: single shared policy, never stored/returned raw ----
        $password = (string) ($data['password'] ?? '');
        PasswordService::assertResetPasswordRules($password, 'password');
        $cost = (int) Config::get('bcrypt_cost', 12);
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);

        // ---- real insert ----
        $userId = $users->createByAdmin([
            'email' => $email,
            'password_hash' => $passwordHash,
            'full_name' => $fullName,
            'role' => $role,
            'plan' => $plan,
            'subscription_status' => $plan === 'pro' ? 'active' : 'none',
            'timezone' => $timezone,
            'locale' => $locale,
        ]);

        // ---- email verification (existing architecture; one-time, hashed, expiring) ----
        $verifications = new EmailVerificationRepository();
        $token = bin2hex(random_bytes(32));
        $verifications->invalidateAllForUser($userId);
        $verifications->create($userId, hash('sha256', $token), 86400);
        $verifyUrl = rtrim((string) Config::get('frontend_url', 'https://veloratrade.ir'), '/') . '/verify-email#token=' . rawurlencode($token);
        $emailSent = false;
        try {
            $emailSent = \Velora\Core\NotificationService::sendVerificationEmail(
                $email,
                $fullName,
                $verifyUrl,
                $userId,
                null,
            );
        } catch (\Throwable $e) {
            $emailSent = false; // honest state — the UI never claims a send that did not happen
        }

        return [
            'id' => $userId,
            'email' => $email,
            'fullName' => $fullName,
            'role' => $role,
            'plan' => $plan,
            'status' => 'active',
            'emailVerified' => false,
            'verificationRequired' => true,
            'emailSent' => $emailSent,
        ];
    }

    /**
     * Admin Invite (Phase 2): create a real privileged account and deliver a
     * secure, one-time acceptance token through the EXISTING password-reset
     * architecture. No plaintext passwords exist anywhere in this flow, no
     * new token system is introduced, and no migration is required.
     *
     * Authorization (server-side, canonical model — never the client):
     *   - the route already requires `users.create` (admin + super_admin);
     *   - invitations are issued ONLY for privileged roles (admin /
     *     super_admin) and therefore additionally require `users.change_role`
     *     (Super Admin only) — the same privilege-escalation rule as
     *     createUser()/setRole().
     *
     * Token model: bin2hex(random_bytes(32)) stored sha256-hashed in the
     * existing password_resets table (TTL 24h, one-time atomic consume,
     * previous tokens invalidated). The token is NEVER returned by the API
     * and NEVER audited.
     *
     * Acceptance: the account is created with an empty password hash
     * (password_verify(x, '') is always false, so it cannot authenticate) and
     * stays unverified until the invitee consumes the token through the
     * existing reset flow, which sets a policy-compliant password and — for
     * invite-grade (empty-hash) accounts only — marks the email verified
     * (the one-time token from the mailbox is the ownership proof).
     */
    public function inviteAdmin(array $data, int $actorId, string $actorRole): array
    {
        // ---- role (authorization first — never trust the client) ----
        $role = strtolower(trim((string) ($data['role'] ?? Role::ADMIN)));
        if (!Role::isValidStored($role) || !Role::isPrivileged($role)) {
            throw new ValidationException('Invitations are only issued for admin-level roles.', ['role' => ['code' => 'INVALID_CHOICE', 'messageKey' => 'errors.validation.choice', 'params' => []]]);
        }
        if (!Role::can($actorRole, Role::P_USERS_CHANGE_ROLE)) {
            throw new ForbiddenException('Only Super Admin may invite admin-level accounts.', 'PRIVILEGE_ESCALATION_DENIED');
        }

        // ---- identity fields ----
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $fullName = trim((string) ($data['fullName'] ?? ($data['full_name'] ?? '')));
        if ($fullName === '') {
            throw new ValidationException('Full name is required.', ['fullName' => ['code' => 'REQUIRED', 'messageKey' => 'errors.validation.required', 'params' => []]]);
        }

        // ---- duplicate email (distinct, actionable codes; covers existing
        // admins and pending accounts without mutating anything silently) ----
        $users = new UserRepository();
        if ($users->emailExists($email)) {
            $existing = $users->findByEmail($email);
            if ($existing !== null && $existing['email_verified_at'] !== null) {
                throw new ConflictException('Email already registered.', 'EMAIL_ALREADY_REGISTERED', 'errors.auth.emailAlreadyRegistered');
            }
            throw new ConflictException('A user with this email is pending email verification.', 'EMAIL_PENDING_VERIFICATION', 'errors.auth.emailPendingVerification');
        }

        // ---- real insert (no password yet; plan/status canonical) ----
        $userId = $users->createByAdmin([
            'email' => $email,
            'password_hash' => '',
            'full_name' => $fullName,
            'role' => $role,
            'plan' => 'free',
            'subscription_status' => 'none',
            'timezone' => 'UTC',
            'locale' => 'fa',
        ]);

        // ---- one-time acceptance token via the EXISTING reset repository ----
        $resets = new \Velora\Auth\PasswordResetRepository();
        $resets->invalidateAllForUser($userId);
        $token = bin2hex(random_bytes(32));
        $resets->create($userId, hash('sha256', $token), self::INVITE_TTL_SEC);

        // Token travels in the URL fragment only (never the path/query), the
        // same anti-leak convention as verification and reset links.
        $inviteUrl = rtrim((string) Config::get('frontend_url', 'https://veloratrade.ir'), '/') . '/reset-password#token=' . rawurlencode($token);

        $emailSent = false;
        try {
            $emailSent = \Velora\Core\NotificationService::sendAdminInviteEmail(
                $email,
                $fullName,
                $inviteUrl,
                $userId,
                null,
            );
        } catch (\Throwable $e) {
            $emailSent = false; // honest state — the UI never claims a send that did not happen
        }

        return [
            'id' => $userId,
            'email' => $email,
            'fullName' => $fullName,
            'role' => $role,
            'plan' => 'free',
            'status' => 'active',
            'emailVerified' => false,
            'invitePending' => true,
            'emailSent' => $emailSent,
        ];
    }

    /** @return array<string,mixed> */
    public function listUsers(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // Prepared-statement bound wildcard search; never concatenated.
            // Native prepares (PDO::ATTR_EMULATE_PREPARES = false) reject a named
            // placeholder reused twice in one statement (HY093) — bind distinct
            // placeholders instead.
            $params['q_email'] = '%' . $search . '%';
            $params['q_name'] = '%' . $search . '%';
            $where[] = '(u.email LIKE :q_email OR u.full_name LIKE :q_name)';
        }
        if (!empty($filters['role']) && Role::isValidStored((string) $filters['role'])) {
            $where[] = 'u.role = :role';
            $params['role'] = (string) $filters['role'];
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['active', 'suspended'], true)) {
            $where[] = 'u.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['plan']) && in_array($filters['plan'], ['free', 'pro'], true)) {
            $where[] = 'u.plan = :plan';
            $params['plan'] = $filters['plan'];
        }
        if (isset($filters['verified'])) {
            if ((string) $filters['verified'] === '1') {
                $where[] = 'u.email_verified_at IS NOT NULL';
            } elseif ((string) $filters['verified'] === '0') {
                $where[] = 'u.email_verified_at IS NULL';
            }
        }
        if (!empty($filters['created_since'])) {
            $where[] = 'u.created_at >= :cs';
            $params['cs'] = $filters['created_since'];
        }

        $sort = (string) ($filters['sort'] ?? 'created_at');
        if (!in_array($sort, self::SORTABLE, true)) {
            $sort = 'created_at';
        }
        $dir = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $clause = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $pdo = Database::connection();

        $countStmt = $pdo->prepare("SELECT COUNT(*) AS n FROM users u $clause");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['n'];

        $select = $this->userProjection('u', $pdo);
        $listStmt = $pdo->prepare(
            "$select $clause ORDER BY u.$sort $dir LIMIT $perPage OFFSET $offset"
        );
        $listStmt->execute($params);
        $items = [];
        foreach ($listStmt->fetchAll() as $row) {
            $items[] = $this->mapUserRow($row, $pdo);
        }

        return [
            'users' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => ($offset + $perPage) < $total,
        ];
    }

    /** @return array<string,mixed> */
    public function userDetail(int $id, int $requesterId, string $requesterRole): array
    {
        $pdo = Database::connection();
        $select = $this->userProjection('u', $pdo);
        $stmt = $pdo->prepare("$select WHERE u.id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }

        $user = $this->mapUserRow($row, $pdo, detailed: true);

        // ---- Identity / security -----------------------------------------
        $sess = $pdo->prepare('SELECT COUNT(*) AS n, MAX(created_at) AS last FROM user_sessions WHERE user_id = :id AND revoked_at IS NULL');
        $sess->execute(['id' => $id]);
        $s = $sess->fetch();
        $user['lastLoginAt'] = $s['last'] ?? null;   // approx (no login-timestamp column); derived from sessions
        $user['activeSessions'] = (int) ($s['n'] ?? 0);

        $dev = $pdo->prepare('SELECT COUNT(*) AS n FROM user_devices WHERE user_id = :id');
        $dev->execute(['id' => $id]);
        $user['knownDevices'] = (int) ($dev->fetch()['n'] ?? 0);

        // ---- Trading accounts --------------------------------------------
        $act = $pdo->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN sync_status = \'CONNECTED\' THEN 1 ELSE 0 END) AS connected,
                    SUM(CASE WHEN sync_status = \'ERROR\' THEN 1 ELSE 0 END) AS sync_errors,
                    MAX(last_synced_at) AS last_sync
             FROM trading_accounts WHERE user_id = :id'
        );
        try {
            $act->execute(['id' => $id]);
            $a = $act->fetch();
            $user['tradingAccounts'] = [
                'total' => (int) ($a['total'] ?? 0),
                'connected' => (int) ($a['connected'] ?? 0),
                'syncErrors' => (int) ($a['sync_errors'] ?? 0),
                'lastSyncAt' => $a['last_sync'] ?? null,
            ];
        } catch (\Throwable $e) {
            $user['tradingAccounts'] = ['total' => 0, 'connected' => 0, 'syncErrors' => 0, 'lastSyncAt' => null];
        }

        // ---- Trading activity -------------------------------------------
        $tr = $pdo->prepare(
            'SELECT COUNT(*) AS total, COALESCE(SUM(profit_loss),0) AS pnl,
                    MAX(open_time) AS recent
             FROM trades WHERE user_id = :id'
        );
        try {
            $tr->execute(['id' => $id]);
            $t = $tr->fetch();
            $user['tradingActivity'] = [
                'totalTrades' => (int) ($t['total'] ?? 0),
                'pnl' => round((float) ($t['pnl'] ?? 0.0), 2),
                'recentTradeAt' => $t['recent'] ?? null,
            ];
        } catch (\Throwable $e) {
            $user['tradingActivity'] = ['totalTrades' => 0, 'pnl' => 0.0, 'recentTradeAt' => null];
        }

        // ---- AI usage ----------------------------------------------------
        $ai = $pdo->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status IN (\'failed\',\'quota_exhausted\',\'timeout\') THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN status=\'success\' THEN tokens_used ELSE 0 END) AS tokens
             FROM ai_requests WHERE user_id = :id'
        );
        try {
            $ai->execute(['id' => $id]);
            $r = $ai->fetch();
            $user['aiUsage'] = [
                'requests' => (int) ($r['total'] ?? 0),
                'failed' => (int) ($r['failed'] ?? 0),
                'tokensUsed' => (int) ($r['tokens'] ?? 0),
                'available' => true,
            ];
        } catch (\Throwable $e) {
            $user['aiUsage'] = ['requests' => 0, 'failed' => 0, 'tokensUsed' => 0, 'available' => false];
        }

        return $user;
    }

    /**
     * Suspend or activate a user. Server-side authorization:
     *  - Admin may only operate on non-privileged (user) targets — never another admin.
     *  - Super Admin may operate on any target, but never suspend self.
     */
    public function setStatus(int $id, string $status, int $requesterId, string $requesterRole, string $actionLabel): array
    {
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new ValidationException('Invalid account status.', ['status' => ['code' => 'INVALID_STATUS']]);
        }
        if ($id === $requesterId) {
            throw new ForbiddenException('Action on self is not allowed.', 'SELF_ACTION_DENIED');
        }

        $pdo = Database::connection();
        $target = $this->findUser($id);
        if ($target === null) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }

        $this->assertTargetManipulable($target, $requesterRole);

        $stmt = $pdo->prepare('UPDATE users SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);

        return ['id' => $id, 'status' => $status];
    }

    /**
     * Change a user's role. Privilege-escalation protection:
     *  - Assigning admin/super_admin requires Super Admin (permission
     *    users.change_role) — role assignment is Super-Admin-only.
     *  - Role changes are Super-Admin-only (users.change_role); subscription is the
     *    separate RBAC-neutral concept.
     *  - An Admin cannot alter the role of a privileged (admin/super_admin) user.
     *  - You cannot change your own role.
     */
    public function setRole(int $id, string $newRole, int $requesterId, string $requesterRole): array
    {
        if (!Role::isValidStored($newRole)) {
            throw new ValidationException('Invalid role.', ['role' => ['code' => 'INVALID_ROLE']]);
        }
        if ($id === $requesterId) {
            throw new ForbiddenException('Changing your own role is not allowed.', 'SELF_ACTION_DENIED');
        }

        $pdo = Database::connection();
        $target = $this->findUser($id);
        if ($target === null) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }

        // A plain admin cannot touch privileged users' roles.
        if ($requesterRole !== Role::SUPER_ADMIN && Role::isPrivileged((string) $target['role'])) {
            throw new ForbiddenException('Cannot modify a privileged user.', 'PRIVILEGED_TARGET');
        }
        // Granting a privileged role requires Super Admin.
        if (Role::isPrivileged($newRole) && $requesterRole !== Role::SUPER_ADMIN) {
            throw new ForbiddenException('Only Super Admin may grant admin-level roles.', 'PRIVILEGE_ESCALATION_DENIED');
        }

        $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
        $stmt->execute(['role' => $newRole, 'id' => $id]);

        return ['id' => $id, 'role' => $newRole];
    }

    /**
     * Manual internal subscription change (no external payment integration —
     * documented gap). Audited. Requires the subscription.manage permission.
     *
     * @param array{plan?:string,status?:string,expires_at?:string|null} $data
     */
    public function setSubscription(int $id, array $data, int $requesterId, string $requesterRole): array
    {
        $pdo = Database::connection();
        $target = $this->findUser($id);
        if ($target === null) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }
        $this->assertTargetManipulable($target, $requesterRole);

        $plan = strtolower(trim((string) ($data['plan'] ?? ($target['plan'] ?? 'free'))));
        if (!in_array($plan, ['free', 'pro'], true)) {
            throw new ValidationException('Invalid plan.', ['plan' => ['code' => 'INVALID_PLAN']]);
        }
        $status = strtolower(trim((string) ($data['status'] ?? ($target['subscription_status'] ?? 'none'))));
        if (!in_array($status, ['none', 'active', 'past_due', 'grace', 'expired', 'cancelled'], true)) {
            throw new ValidationException('Invalid subscription status.', ['status' => ['code' => 'INVALID_SUBSCRIPTION_STATUS']]);
        }

        // Check whether the subscription columns exist (v1.3). If not, report unavailable.
        if (!$this->hasSubscriptionColumns($pdo)) {
            throw new ValidationException('Subscription layer is not available (v1.3 migration not applied).', ['subscription' => ['code' => 'SUBSCRIPTION_UNAVAILABLE']]);
        }

        $set = ['plan = :plan', 'subscription_status = :status', 'plan_updated_at = CURRENT_TIMESTAMP', 'updated_at = CURRENT_TIMESTAMP'];
        $params = ['plan' => $plan, 'status' => $status, 'id' => $id];

        if (array_key_exists('expires_at', $data)) {
            $set[] = 'plan_expires_at = :expires';
            $params['expires'] = ($data['expires_at'] === null || $data['expires_at'] === '') ? null : $data['expires_at'];
        }
        if (array_key_exists('started_at', $data) && !empty($data['started_at'])) {
            $set[] = 'plan_started_at = :started';
            $params['started'] = $data['started_at'];
        } elseif ($plan === 'pro' && empty($target['plan_started_at'])) {
            $set[] = 'plan_started_at = COALESCE(plan_started_at, CURRENT_TIMESTAMP)';
        }

        $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = :id');
        $stmt->execute($params);

        return ['id' => $id, 'plan' => $plan, 'status' => $status];
    }

    /**
     * Phase E — safe per-user trading-account list. Only non-secret metadata is
     * returned; connection_credentials_encrypted is NEVER projected.
     *
     * @return list<array{id:int,provider:string,platform:string,broker:?string,server:?string,account:string,accountType:string,syncStatus:string,lastSyncedAt:?string,connectedAt:?string}>
     */
    public function userAccounts(int $id, int $requesterId, string $requesterRole): array
    {
        if ($this->findUser($id) === null) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }
        $stmt = Database::connection()->prepare(
            'SELECT id, provider, platform, broker, server, mt_login, account_type, sync_status,
                    last_synced_at, connected_at, auto_sync_enabled
             FROM trading_accounts WHERE user_id = :id ORDER BY id'
        );
        $stmt->execute(['id' => $id]);
        return array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'provider' => $r['provider'] ?? 'MANUAL',
                'platform' => $r['platform'] ?? 'MANUAL',
                'broker' => $r['broker'],
                'server' => $r['server'],
                'account' => $r['mt_login'] !== null ? (string) $r['mt_login'] : null,
                'accountType' => $r['account_type'] ?? 'STANDARD',
                'syncStatus' => $r['sync_status'] ?? 'DISCONNECTED',
                'lastSyncedAt' => $r['last_synced_at'],
                'connectedAt' => $r['connected_at'],
                'autoSyncEnabled' => (bool) ($r['auto_sync_enabled'] ?? false),
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Phase E — per-user login/session activity derived from user_sessions.
     * Tokens/hashes are never returned; only safe event type, time, IP and UA.
     *
     * @return list<array{event:string,time:string,ip:?string,userAgent:?string,result:string}>
     */
    public function userActivity(int $id, int $requesterId, string $requesterRole, int $page = 1, int $perPage = 25): array
    {
        if ($this->findUser($id) === null) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = Database::connection()->prepare(
            'SELECT id, ip_address, user_agent, created_at, revoked_at, expires_at
             FROM user_sessions WHERE user_id = :id
             ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $items[] = [
                'event' => $r['revoked_at'] !== null ? 'session.revoked' : 'session.created',
                'time' => $r['revoked_at'] !== null ? $r['revoked_at'] : $r['created_at'],
                'ip' => $r['ip_address'],
                'userAgent' => $r['user_agent'],
                'result' => $r['revoked_at'] !== null ? 'revoked' : 'active',
            ];
        }
        $c = Database::connection()->prepare('SELECT COUNT(*) AS n FROM user_sessions WHERE user_id = :id');
        $c->execute(['id' => $id]);
        return [
            'items' => $items,
            'total' => (int) $c->fetch()['n'],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Phase E — per-user audit trail (target_type='user', target_id=<id>).
     * Sensitive correlation fields (ip, context id) are gated by the caller via
     * P_AUDIT_SENSITIVE_VIEW (Super Admin) — mirrored in the controller.
     */
    public function userAudit(int $id, int $requesterId, string $requesterRole, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        if ($this->findUser($id) === null) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }
        $audit = new \Velora\Admin\AdminAuditLogRepository();
        $filters['target_type'] = 'user';
        $filters['target_id'] = (string) $id;
        return $audit->list($filters, $page, $perPage);
    }

    /** Revoke all live sessions of a user (logout everywhere). Idempotent. */
    /**
     * Phase 3 — real sessions/devices/login-history visibility for User360.
     *
     * Reads require `users.view` (enforced by the route); revocation requires
     * `users.suspend` (enforced by the route) — the SAME authority as the
     * pre-existing revoke-all action, plus the same service guards
     * (SELF_ACTION_DENIED + assertTargetManipulable). Sessions are listed
     * WITHOUT their token hashes (never secrets); active status is derived
     * from real state (revoked_at IS NULL AND expires_at >= now). Devices
     * are read-only by architecture (no revocation concept exists in the
     * schema — documented limitation, not faked). Login history rows come
     * from auth_events (real recorded events only — nothing retrofitted).
     */

    /** @return array{sessions:array<int,array<string,mixed>>,total:int,page:int,perPage:int} */
    public function userSessions(int $id, int $requesterId, string $requesterRole, int $page = 1, int $perPage = 25): array
    {
        if ($this->findUser($id) === null) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $now = gmdate('Y-m-d H:i:s');
        $pdo = Database::connection();

        $c = $pdo->prepare('SELECT COUNT(*) AS n FROM user_sessions WHERE user_id = :id');
        $c->execute(['id' => $id]);
        $total = (int) $c->fetch()['n'];

        $offset = ($page - 1) * $perPage;
        $q = $pdo->prepare(
            "SELECT id, ip_address, user_agent, expires_at, revoked_at, created_at
             FROM user_sessions WHERE user_id = :id
             ORDER BY created_at DESC, id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $q->execute(['id' => $id]);
        $sessions = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'active' => $r['revoked_at'] === null && (string) $r['expires_at'] >= $now,
            'createdAt' => (string) $r['created_at'],
            'expiresAt' => $r['expires_at'] !== null ? (string) $r['expires_at'] : null,
            'revokedAt' => $r['revoked_at'] !== null ? (string) $r['revoked_at'] : null,
            'ipAddress' => $r['ip_address'] !== null ? (string) $r['ip_address'] : null,
            'userAgent' => $r['user_agent'] !== null ? (string) $r['user_agent'] : null,
        ], $q->fetchAll());

        return ['sessions' => $sessions, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /** Idempotent single-session revocation; audited by the controller. @return array{id:int,changed:bool} */
    public function revokeSession(int $id, int $sessionId, int $requesterId, string $requesterRole): array
    {
        if ($id === $requesterId) {
            throw new ForbiddenException('Action on self is not allowed.', 'SELF_ACTION_DENIED');
        }
        $target = $this->findUser($id);
        if ($target === null) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }
        $this->assertTargetManipulable($target, $requesterRole);

        $stmt = Database::connection()->prepare(
            'SELECT id, revoked_at, expires_at FROM user_sessions WHERE id = :sid AND user_id = :id LIMIT 1'
        );
        $stmt->execute(['sid' => $sessionId, 'id' => $id]);
        $session = $stmt->fetch();
        if ($session === false) {
            // Unknown id OR a session belonging to a different user — identical answer.
            throw new NotFoundException('Session not found.', 'SESSION_NOT_FOUND');
        }

        // revoke() itself is idempotent (UPDATE ... WHERE revoked_at IS NULL);
        // `changed` reflects real usability: an already-revoked OR already
        // expired session is dead regardless, so revoking it changes nothing.
        $now = gmdate('Y-m-d H:i:s');
        $changed = $session['revoked_at'] === null && (string) $session['expires_at'] >= $now;
        (new SessionRepository())->revoke($sessionId);
        return ['id' => $sessionId, 'changed' => $changed];
    }

    /** @return array{devices:array<int,array<string,mixed>>,total:int,page:int,perPage:int} */
    public function userDevices(int $id, int $requesterId, string $requesterRole, int $page = 1, int $perPage = 25): array
    {
        if ($this->findUser($id) === null) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        // Existing repository deliberately excludes the internal fingerprint
        // correlation key; ordering: most recently seen first.
        $all = (new UserDeviceRepository())->listForUser($id);
        $total = count($all);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);
        $devices = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'ipAddress' => $r['ip_address'] !== null ? (string) $r['ip_address'] : null,
            'userAgent' => $r['user_agent'] !== null ? (string) $r['user_agent'] : null,
            'firstSeenAt' => (string) $r['first_seen_at'],
            'lastSeenAt' => (string) $r['last_seen_at'],
        ], $slice);

        return ['devices' => $devices, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /** @return array{events:array<int,array<string,mixed>>,total:int,page:int,perPage:int} */
    public function loginHistory(int $id, int $requesterId, string $requesterRole, int $page = 1, int $perPage = 25, ?string $result = null): array
    {
        if ($this->findUser($id) === null) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }
        if ($result !== null && $result !== '' && !in_array($result, ['success', 'failure'], true)) {
            throw new ValidationException('Invalid result filter.', ['result' => ['code' => 'INVALID_CHOICE', 'messageKey' => 'errors.validation.choice', 'params' => []]]);
        }
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        $res = (new AuthEventRepository())->listForUser($id, $page, $perPage, $result !== '' ? $result : null);

        return ['events' => $res['items'], 'total' => $res['total'], 'page' => $page, 'perPage' => $perPage];
    }

    public function revokeSessions(int $id, int $requesterId, string $requesterRole): int
    {
        if ($id === $requesterId) {
            throw new ForbiddenException('Action on self is not allowed.', 'SELF_ACTION_DENIED');
        }
        $target = $this->findUser($id);
        if ($target === null) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }
        $this->assertTargetManipulable($target, $requesterRole);
        $repo = new \Velora\Auth\SessionRepository();
        $c = Database::connection()->prepare('SELECT COUNT(*) AS n FROM user_sessions WHERE user_id = :id AND revoked_at IS NULL');
        $c->execute(['id' => $id]);
        $n = (int) $c->fetch()['n'];
        $repo->revokeAllForUser($id);
        return $n;
    }

    /**
     * Phase 3 B-1 (decision D1): Admin-triggered email verification.
     *
     * Idempotent by construction: email_verified_at is written only while it
     * is still NULL (UPDATE ... WHERE email_verified_at IS NULL); an already
     * verified user yields changed=false and is NOT an error. Outstanding
     * email verification tokens are ALWAYS invalidated (also on the
     * idempotent path) so no stale token survives the admin action.
     *
     * Authorization mirrors setStatus(): self-action denied; a plain Admin
     * cannot operate on privileged users (assertTargetManipulable). The
     * UPDATE and the token invalidation commit atomically (repo transaction
     * convention). Timestamps use the project's UTC convention (gmdate).
     */
    public function verifyEmail(int $id, int $requesterId, string $requesterRole): array
    {
        if ($id === $requesterId) {
            throw new ForbiddenException('Action on self is not allowed.', 'SELF_ACTION_DENIED');
        }
        $target = $this->findUser($id);
        if ($target === null) {
            throw new NotFoundException('User not found.', 'USER_NOT_FOUND');
        }
        $this->assertTargetManipulable($target, $requesterRole);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE users SET email_verified_at = :ts WHERE id = :id AND email_verified_at IS NULL');
            $stmt->execute(['ts' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
            $changed = $stmt->rowCount() === 1;

            (new \Velora\Auth\EmailVerificationRepository())->invalidateAllForUser($id);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['changed' => $changed, 'user' => $this->userDetail($id, $requesterId, $requesterRole)];
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------
    private function findUser(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT id, role, status, plan, subscription_status FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function assertTargetManipulable(array $target, string $requesterRole): void
    {
        // A plain Admin cannot suspend/change subscriptions of privileged users.
        if ($requesterRole !== Role::SUPER_ADMIN && Role::isPrivileged((string) ($target['role'] ?? ''))) {
            throw new ForbiddenException('Cannot operate on a privileged user.', 'PRIVILEGED_TARGET');
        }
    }

    private function userProjection(string $alias, \PDO $pdo): string
    {
        $cols = "$alias.id, $alias.email, $alias.full_name, $alias.role, $alias.status, "
            . "$alias.email_verified_at, $alias.created_at, $alias.updated_at, $alias.locale, $alias.timezone";
        if ($this->hasColumn($pdo, 'users', 'plan')) {
            $cols .= ", $alias.plan, $alias.subscription_status, $alias.plan_started_at, "
                . "$alias.plan_expires_at, $alias.plan_updated_at";
        }
        return "SELECT $cols FROM users $alias";
    }

    private function mapUserRow(array $row, \PDO $pdo, bool $detailed = false): array
    {
        $hasSub = $this->hasColumn($pdo, 'users', 'plan');
        $user = [
            'id' => (int) $row['id'],
            'email' => $row['email'],
            'fullName' => $row['full_name'],
            'role' => $row['role'],
            'status' => $row['status'],
            'emailVerified' => $row['email_verified_at'] !== null,
            'emailVerifiedAt' => $row['email_verified_at'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'locale' => $row['locale'] ?? 'fa',
            'timezone' => $row['timezone'] ?? 'UTC',
        ];
        if ($hasSub) {
            $user['plan'] = $row['plan'] ?? 'free';
            $user['subscriptionStatus'] = $row['subscription_status'] ?? 'none';
            $user['planStartedAt'] = $row['plan_started_at'] ?? null;
            $user['planExpiresAt'] = $row['plan_expires_at'] ?? null;
            $user['planUpdatedAt'] = $row['plan_updated_at'] ?? null;
        } else {
            $user['plan'] = null;
            $user['subscriptionStatus'] = null;
            $user['subscriptionAvailable'] = false;
        }
        return $user;
    }

    private function hasSubscriptionColumns(\PDO $pdo): bool
    {
        return $this->hasColumn($pdo, 'users', 'plan');
    }

    private function hasColumn(\PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = "$table.$column";
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        try {
            $driver = (string) ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) ?? '');
            if ($driver === 'sqlite') {
                $rows = $pdo->query("PRAGMA table_info($table)")->fetchAll();
                $cache[$key] = false;
                foreach ($rows as $r) {
                    if (($r['name'] ?? null) === $column) {
                        return $cache[$key] = true;
                    }
                }
                return false;
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
            $stmt->execute(['t' => $table, 'c' => $column]);
            $cache[$key] = ((int) $stmt->fetch()['n']) > 0;
        } catch (\Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}
