<?php

declare(strict_types=1);

namespace Velora\Auth;

use Velora\Core\Config;
use Velora\Core\Exceptions\ValidationException;
use Velora\Core\NotificationService;

/**
 * مدیریت رمز عبور:
 *  - تغییر رمز (کاربر واردشده، نیاز به رمز فعلی + ارسال ایمیل اطلاع‌رسانی)
 *  - فراموشی رمز (ارسال لینک بازیابی با قالب مرجع)
 *  - بازنشانی رمز (با توکن یک‌بارمصرف + ارسال ایمیل تغییر موفق رمز عبور)
 */
final class PasswordService
{
    private const RESET_TTL_SEC = 3600; // لینک بازیابی ۱ ساعت معتبر است

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly PasswordResetRepository $resets = new PasswordResetRepository(),
    ) {
    }

    /**
     * تغییر رمز برای کاربر واردشده.
     * @param array{currentPassword:string, newPassword:string} $data
     */
    public function changePassword(int $userId, array $data): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new ValidationException('Validation failed.', ['user' => 'کاربر یافت نشد.']);
        }

        if (!password_verify($data['currentPassword'], $user['password_hash'])) {
            throw new ValidationException('Validation failed.', ['currentPassword' => 'رمز عبور فعلی اشتباه است.']);
        }

        if ($data['currentPassword'] === $data['newPassword']) {
            throw new ValidationException('Validation failed.', ['newPassword' => 'رمز جدید باید متفاوت از رمز فعلی باشد.']);
        }

        $this->updatePasswordHash((int) $user['id'], $data['newPassword']);

        // باطل‌کردن همه نشست‌های قبلی (امنیت)
        (new SessionRepository())->revokeAllForUser((int) $user['id']);

        // ارسال ایمیل تغییر موفق رمز عبور
        NotificationService::sendPasswordChangedEmail(
            $user['email'],
            $user['full_name'] ?: $user['email'],
            (int) $user['id']
        );
    }

    /**
     * فراموشی رمز: ساخت توکن و ارسال لینک به ایمیل.
     */
    public function forgotPassword(string $email): void
    {
        $user = $this->users->findByEmail(mb_strtolower(trim($email)));
        if ($user === null) {
            return; // کاربر وجود ندارد — ولی پیام موفق برمی‌گردد
        }

        $userId = (int) $user['id'];
        $this->resets->invalidateAllForUser($userId);

        $token = bin2hex(random_bytes(32));
        $this->resets->create($userId, hash('sha256', $token), self::RESET_TTL_SEC);

        $frontendBase = Config::get('frontend_url', 'https://veloratrade.ir');
        $resetUrl = rtrim($frontendBase, '/') . '/reset-password?token=' . $token;

        NotificationService::sendPasswordResetTokenEmail(
            $user['email'],
            $user['full_name'] ?: $user['email'],
            $resetUrl,
            $userId
        );
    }

    /**
     * بازنشانی رمز با توکن.
     * @param array{token:string, newPassword:string} $data
     */
    public function resetPassword(array $data): void
    {
        $tokenHash = hash('sha256', $data['token']);
        $reset = $this->resets->findValidByHash($tokenHash);

        if ($reset === null) {
            throw new ValidationException('لینک بازیابی نامعتبر است یا اعتبار آن به پایان رسیده است.', ['token' => 'لینک بازیابی نامعتبر یا منقضی شده است.']);
        }

        $newPassword = (string) $data['newPassword'];
        self::assertResetPasswordRules($newPassword);

        $user = $this->users->findById((int) $reset['user_id']);
        if ($user === null) {
            throw new ValidationException('کاربر این لینک یافت نشد.');
        }
        if (password_verify($newPassword, (string) $user['password_hash'])) {
            throw new ValidationException('رمز جدید نباید با رمز عبور قبلی یکسان باشد.', ['newPassword' => 'رمز جدید را متفاوت از رمز قبلی انتخاب کنید.']);
        }

        // مصرف توکن و تغییر هش باید اتمیک باشند. نسخه قبلی پس از تغییر رمز، متد
        // ناموجود markUsed() را صدا می‌زد و با HTTP 500 خارج می‌شد؛ در نتیجه رمز
        // عوض می‌شد ولی پاسخ شکست می‌خورد. consume() قرارداد واقعی repository است.
        \Velora\Core\Database::transaction(function () use ($reset, $tokenHash, $newPassword): void {
            if (!$this->resets->consume((int) $reset['id'], $tokenHash)) {
                throw new ValidationException(
                    'لینک بازیابی نامعتبر است یا قبلاً استفاده شده است.',
                    ['token' => 'لینک بازیابی نامعتبر یا استفاده‌شده است.'],
                );
            }
            $this->updatePasswordHash((int) $reset['user_id'], $newPassword);
            $this->resets->invalidateAllForUser((int) $reset['user_id']);
        });

        // خروج از همه نشست‌ها
        (new SessionRepository())->revokeAllForUser((int) $reset['user_id']);

        // ارسال ایمیل موفقیت بازنشانی رمز عبور
        NotificationService::sendPasswordChangedEmail(
            $user['email'],
            $user['full_name'] ?: $user['email'],
            (int) $reset['user_id']
        );
    }

    private function updatePasswordHash(int $userId, string $newPassword): void
    {
        $cost = (int) Config::get('bcrypt_cost', 12);
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => $cost]);

        // NOW() is MySQL-only; bind the timestamp so SQLite deployments work too.
        $stmt = \Velora\Core\Database::connection()->prepare(
            'UPDATE users SET password_hash = :hash, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'hash' => $hash,
            'now' => gmdate('Y-m-d H:i:s'),
            'id' => $userId,
        ]);
    }

    private static function assertResetPasswordRules(string $password): void
    {
        if (mb_strlen($password) < 8) {
            throw new ValidationException('رمز عبور باید حداقل ۸ کاراکتر باشد.', ['newPassword' => 'حداقل ۸ کاراکتر وارد کنید.']);
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new ValidationException('رمز عبور باید حداقل شامل یک حرف انگلیسی و یک عدد باشد.', ['newPassword' => 'از حداقل یک حرف انگلیسی و یک عدد استفاده کنید.']);
        }
    }
}
