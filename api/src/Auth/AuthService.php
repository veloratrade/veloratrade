<?php

declare(strict_types=1);

namespace Velora\Auth;

use Velora\Core\Config;
use Velora\Core\Crypto;
use Velora\Core\Exceptions\ConflictException;
use Velora\Core\Exceptions\UnauthorizedException;
use Velora\Core\Exceptions\ValidationException;
use Velora\Core\Jwt;
use Velora\Core\NotificationService;
use Velora\Core\UserAchievementRepository;

/**
 * Auth business logic: register, login (dual-token JWT), refresh, logout.
 * Integrated with master email templates, user_devices, and user_achievements.
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly SessionRepository $sessions = new SessionRepository(),
        private readonly EmailVerificationRepository $verifications = new EmailVerificationRepository(),
    ) {
    }

    /**
     * @param array{email:string, password:string, full_name?:string, timezone?:string} $data
     */
    public function register(array $data, ?string $ip, ?string $userAgent): array
    {
        $email = mb_strtolower(trim($data['email']));
        $fullName = trim((string) ($data['fullName'] ?? $data['full_name'] ?? ''));
        $timezone = trim((string) ($data['timezone'] ?? 'UTC'));
        $notificationLocale = trim((string) ($data['notificationLocale'] ?? '')) ?: null;

        // R4: capture the UI locale the user registered under so their saved
        // preference is correct on first login instead of defaulting to 'fa'.
        $uiLocale = trim((string) ($data['locale'] ?? '')) ?: null;
        $i18n = \Velora\Core\Locale\LocaleManager::getInstance();
        if ($uiLocale !== null) {
            $uiLocale = $i18n->supports($uiLocale) ? $i18n->resolve($uiLocale) : null;
        }
        if ($uiLocale === null && $notificationLocale !== null) {
            $uiLocale = $i18n->supports($notificationLocale)
                ? $i18n->resolve($notificationLocale)
                : null;
        }

        if ($this->users->emailExists($email)) {
            $existing = $this->users->findByEmail($email);
            if ($existing !== null && $existing['email_verified_at'] !== null) {
                throw new ConflictException('Email already registered.', 'EMAIL_ALREADY_REGISTERED', 'errors.auth.emailAlreadyRegistered');
            }
            if ($existing !== null && $existing['email_verified_at'] === null) {
                $uid = (int) $existing['id'];
                $recentCount = $this->verifications->countRecentForUser($uid, 86400);
                if ($recentCount >= 3) {
                    throw new ValidationException('Verification email limit reached.', [
                        'email' => [
                            'code' => 'VERIFICATION_LIMIT',
                            'messageKey' => 'errors.auth.verificationLimit',
                            'params' => ['max' => 3, 'hours' => 24],
                        ],
                    ]);
                }
                // ۲. بررسی وقفه زمانی بین هر درخواست (حداقل ۱ دقیقه)
                $latest = $this->verifications->latestForUser($uid);
                if ($latest !== null && strtotime((string) $latest['created_at']) > (time() - 60)) {
                    throw new ValidationException('Verification retry interval not elapsed.', [
                        'email' => [
                            'code' => 'VERIFICATION_RETRY_DELAY',
                            'messageKey' => 'errors.auth.verificationRetryDelay',
                            'params' => ['minutes' => 1],
                        ],
                    ]);
                }

                $token = bin2hex(random_bytes(32));
                $this->verifications->invalidateAllForUser($uid);
                $this->verifications->create($uid, hash('sha256', $token), 86400);
                $verifyUrl = rtrim((string) Config::get('frontend_url', 'https://veloratrade.ir'), '/') . '/verify-email#token=' . rawurlencode($token);

                $fn = trim((string) ($existing['full_name'] ?? ''));
                $emailLocale = NotificationService::resolveEmailLocale(
                    $existing['locale'] ?? null,
                    $notificationLocale,
                );
                try {
                    NotificationService::sendVerificationEmail(
                        $email,
                        $fn !== '' ? $fn : $email,
                        $verifyUrl,
                        $uid,
                        $emailLocale,
                    );
                } catch (\Throwable $e) {}

                return [
                    'verificationRequired' => true,
                    'email' => $email,
                    'messageKey' => 'auth.verificationResent',
                    'params' => (object) [],
                ];
            }
        }

        $cost = (int) Config::get('bcrypt_cost', 12);
        // BUG-A5: همان سیاست واحد رمز که در بازنشانی/تغییر اعمال می‌شود (منبع مشترک، بدون تکرار قواعد)
        PasswordService::assertResetPasswordRules((string) $data['password'], 'password');

        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => $cost]);

        $createData = [
            'email' => $email,
            'password_hash' => $passwordHash,
            'full_name' => $fullName,
            'timezone' => $timezone,
        ];
        if ($uiLocale !== null) {
            $createData['locale'] = $uiLocale;
            $createData['locale_source'] = 'user';
        }
        $userId = $this->users->create($createData);

        $token = bin2hex(random_bytes(32));
        $this->verifications->invalidateAllForUser($userId);
        $this->verifications->create($userId, hash('sha256', $token), 86400);
        $verifyUrl = rtrim((string) Config::get('frontend_url', 'https://veloratrade.ir'), '/') . '/verify-email#token=' . rawurlencode($token);

        try {
            NotificationService::sendVerificationEmail(
                $email,
                $fullName !== '' ? $fullName : $email,
                $verifyUrl,
                $userId,
                NotificationService::resolveEmailLocale(null, $notificationLocale),
            );
        } catch (\Throwable $e) {}

        return ['verificationRequired' => true, 'email' => $email];
    }

    public function verifyEmail(string $token, ?string $notificationLocale = null): bool
    {
        $notificationLocale = trim((string) $notificationLocale) ?: null;
        $record = $this->verifications->findValid(hash('sha256', $token));
        if ($record === null) {
            throw new UnauthorizedException('Verification link is invalid or expired.', 'VERIFICATION_LINK_INVALID', 'errors.auth.verificationLinkInvalid');
        }
        $user = $this->users->findById((int) $record['user_id']);
        if ($record['verified_at'] !== null) {
            // Old verification rows are also marked via verified_at when invalidated.
            // Treat them as "already verified" only if the user is actually verified.
            if ($user !== null && $user['email_verified_at'] !== null) {
                return true;
            }
            throw new UnauthorizedException('Verification link is invalid or expired.', 'VERIFICATION_LINK_INVALID', 'errors.auth.verificationLinkInvalid');
        }
        if (strtotime((string) $record['expires_at']) < time()) {
            throw new UnauthorizedException('Verification link has expired.', 'VERIFICATION_LINK_EXPIRED', 'errors.auth.verificationLinkExpired');
        }

        $firstVerification = \Velora\Core\Database::transaction(function () use ($record): bool {
            if (!$this->verifications->consume((int) $record['id'])) {
                $freshUser = $this->users->findById((int) $record['user_id']);
                if ($freshUser !== null && $freshUser['email_verified_at'] !== null) {
                    return false;
                }
                throw new UnauthorizedException('Verification link is invalid or expired.', 'VERIFICATION_LINK_INVALID', 'errors.auth.verificationLinkInvalid');
            }
            $stmt = \Velora\Core\Database::connection()->prepare(
                'UPDATE users SET email_verified_at = CURRENT_TIMESTAMP WHERE id = :id AND email_verified_at IS NULL'
            );
            $stmt->execute(['id' => (int) $record['user_id']]);
            if ($stmt->rowCount() !== 1) {
                throw new UnauthorizedException('Verification link is invalid or expired.', 'VERIFICATION_LINK_INVALID', 'errors.auth.verificationLinkInvalid');
            }
            return true;
        });

        if (!$firstVerification) {
            return true;
        }

        $user = $user ?? $this->users->findById((int) $record['user_id']);
        if ($user !== null) {
            $dashboardUrl = rtrim((string) Config::get('frontend_url', 'https://veloratrade.ir'), '/') . '/dashboard';
            $profileUrl = rtrim((string) Config::get('frontend_url', 'https://veloratrade.ir'), '/') . '/profile';
            $emailLocale = NotificationService::resolveEmailLocale(
                $user['locale'] ?? null,
                $notificationLocale,
            );

            NotificationService::sendWelcomeEmail(
                $user['email'],
                $user['full_name'] ?: $user['email'],
                $dashboardUrl,
                (int) $user['id'],
                $emailLocale,
            );

            $achievementTitleKey = 'achievements.emailVerified.title';
            $achievementDescriptionKey = 'achievements.emailVerified.description';
            if ((new UserAchievementRepository())->unlock(
                (int) $user['id'],
                'EMAIL_VERIFIED',
                $achievementTitleKey,
                $achievementDescriptionKey,
            )) {
                NotificationService::sendAchievementUnlockedEmail(
                    $user['email'],
                    $user['full_name'] ?: $user['email'],
                    $achievementTitleKey,
                    $achievementDescriptionKey,
                    $profileUrl,
                    (int) $user['id'],
                    $emailLocale,
                );
            }
        }

        return false;
    }

    /**
     * ارسال مجدد ایمیل تأیید ثبت‌نام (برای لینک‌های منقضی‌شده پس از ۲۴ ساعت یا دریافت‌نشده)
     * @param array{email:string} $data
     */
    public function resendVerification(array $data): array
    {
        $email = mb_strtolower(trim($data['email'] ?? ''));
        $notificationLocale = trim((string) ($data['notificationLocale'] ?? '')) ?: null;
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Invalid email address.', [
                'email' => ['code' => 'INVALID_EMAIL', 'messageKey' => 'errors.validation.email', 'params' => []],
            ]);
        }

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            return [
                'sent' => true,
                'alreadyVerified' => false,
                'messageKey' => 'auth.verificationSentIfRegistered',
                'params' => (object) [],
            ];
        }

        if ($user['email_verified_at'] !== null) {
            // BUG-A7: ضد-enumeration — پاسخ کاربر تأییدشده دقیقاً همان پاسخ
            // «آدرس ناموجود/تأییدنشده» است؛ وضعیت تأیید هرگز فاش نمی‌شود.
            return [
                'sent' => true,
                'alreadyVerified' => false,
                'messageKey' => 'auth.verificationSentIfRegistered',
                'params' => (object) [],
            ];
        }

        $userId = (int) $user['id'];

        // ۱. بررسی سقف تعداد مجاز در ۲۴ ساعت گذشته (حداکثر ۳ بار)
        $recentCount = $this->verifications->countRecentForUser($userId, 86400);
        if ($recentCount >= 3) {
            throw new ValidationException('Verification email limit reached.', [
                'email' => [
                    'code' => 'VERIFICATION_LIMIT',
                    'messageKey' => 'errors.auth.verificationLimit',
                    'params' => ['max' => 3, 'hours' => 24],
                ],
            ]);
        }

        // ۲. بررسی وقفه زمانی بین هر درخواست (حداقل ۱ دقیقه)
        $latest = $this->verifications->latestForUser($userId);
        if ($latest !== null && strtotime((string) $latest['created_at']) > (time() - 60)) {
            throw new ValidationException('Verification retry interval not elapsed.', [
                        'email' => [
                            'code' => 'VERIFICATION_RETRY_DELAY',
                            'messageKey' => 'errors.auth.verificationRetryDelay',
                            'params' => ['minutes' => 1],
                        ],
                    ]);
        }

        $token = bin2hex(random_bytes(32));
        $this->verifications->invalidateAllForUser($userId);
        $this->verifications->create($userId, hash('sha256', $token), 86400);

        $verifyUrl = rtrim((string) Config::get('frontend_url', 'https://veloratrade.ir'), '/') . '/verify-email#token=' . rawurlencode($token);

        $fullName = trim((string) ($user['full_name'] ?? ''));
        try {
            NotificationService::sendVerificationEmail(
                $email,
                $fullName !== '' ? $fullName : $email,
                $verifyUrl,
                $userId,
                NotificationService::resolveEmailLocale($user['locale'] ?? null, $notificationLocale),
            );
        } catch (\Throwable $e) {}

        return [
            // BUG-A7: حتی پس از ارسال واقعی، پیام عمومیِ ضد-enumeration برمی‌گردد
            'sent' => true,
            'alreadyVerified' => false,
            'messageKey' => 'auth.verificationSentIfRegistered',
            'params' => (object) [],
        ];
    }

    /**
     * @param array{email:string, password:string} $data
     */
    public function login(array $data, ?string $ip, ?string $userAgent): array
    {
        $email = mb_strtolower(trim($data['email']));
        $notificationLocale = trim((string) ($data['notificationLocale'] ?? '')) ?: null;
        $user = $this->users->findByEmail($email);

        if ($user === null || !password_verify($data['password'], $user['password_hash'])) {
            throw new UnauthorizedException('Invalid credentials.', 'INVALID_CREDENTIALS', 'errors.auth.invalidCredentials');
        }

        if ($user['status'] !== 'active') {
            throw new UnauthorizedException('Account is inactive.', 'ACCOUNT_INACTIVE', 'errors.auth.accountInactive');
        }
        if ($user['email_verified_at'] === null) {
            throw new UnauthorizedException('Email verification required.', 'EMAIL_NOT_VERIFIED', 'errors.auth.emailNotVerified');
        }

        $userId = (int) $user['id'];
        $isNewDevice = (new UserDeviceRepository())->recordAndCheckNewDevice($userId, $ip, $userAgent);
        if ($isNewDevice) {
            NotificationService::sendNewDeviceDetectedEmail(
                $user['email'],
                $user['full_name'] ?: $user['email'],
                $ip ?? '0.0.0.0',
                $userAgent ?? '',
                gmdate('Y-m-d H:i:s') . ' UTC',
                $userId,
                NotificationService::resolveEmailLocale($user['locale'] ?? null, $notificationLocale),
            );
        }

        return $this->issueTokenPair($userId, $ip, $userAgent);
    }

    public function refresh(string $refreshToken, ?string $ip, ?string $userAgent): array
    {
        $hash = hash('sha256', $refreshToken);
        $session = $this->sessions->findByRefreshHash($hash);

        if ($session === null || $session['revoked_at'] !== null) {
            throw new UnauthorizedException('Invalid token.', 'INVALID_TOKEN', 'errors.auth.invalidToken');
        }

        $expires = strtotime((string) $session['expires_at']);
        if ($expires === false || $expires < time()) {
            throw new UnauthorizedException('Session expired.', 'SESSION_EXPIRED', 'errors.auth.sessionExpired');
        }

        $user = $this->users->findById((int) $session['user_id']);
        if ($user === null || $user['status'] !== 'active') {
            throw new UnauthorizedException('Account is inactive.', 'ACCOUNT_INACTIVE', 'errors.auth.accountInactive');
        }

        return $this->issueTokenPair(
            (int) $user['id'],
            $ip,
            $userAgent,
            sessionId: (int) $session['id'],
            expectedRefreshHash: $hash,
        );
    }

    public function logout(string $refreshToken): void
    {
        $hash = hash('sha256', $refreshToken);
        $session = $this->sessions->findByRefreshHash($hash);
        if ($session !== null) {
            $this->sessions->revoke((int) $session['id']);
        }
    }

    public function authenticate(?string $token): array
    {
        if ($token === null) {
            throw new UnauthorizedException('Access token is missing.', 'ACCESS_TOKEN_MISSING', 'errors.auth.accessTokenMissing');
        }

        $secret = (string) Config::get('jwt_secret');
        $claims = Jwt::decode($token, $secret);
        if ($claims === null || !isset($claims['sub'])) {
            throw new UnauthorizedException('Access token is invalid or expired.', 'INVALID_TOKEN', 'errors.auth.invalidToken');
        }

        $user = $this->users->findById((int) $claims['sub']);
        if ($user === null || $user['status'] !== 'active') {
            throw new UnauthorizedException('Account is inactive.', 'ACCOUNT_INACTIVE', 'errors.auth.accountInactive');
        }

        // اصلاح باگ امنیتی: نشست باید فعال و غیرمنقضی باشد.
        // بدون این چک، access token بعد از logout یا تغییر رمز (که همه نشست‌ها را
        // باطل می‌کند) تا انقضای JWT (۱۵ دقیقه) همچنان معتبر می‌ماند.
        $session = (new SessionRepository())->findByAccessHash(hash('sha256', $token), (int) $user['id']);
        if ($session === null
            || $session['revoked_at'] !== null
            || ($session['expires_at'] !== null && strtotime((string) $session['expires_at']) < time())) {
            throw new UnauthorizedException('Session was revoked.', 'SESSION_REVOKED', 'errors.auth.sessionRevoked');
        }

        return $user;
    }

    public function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'fullName' => $user['full_name'],
            'role' => $user['role'],
            'timezone' => $user['timezone'],
            'locale' => $user['locale'] ?? 'fa',
            'createdAt' => $user['created_at'],
        ];
    }

    private function issueTokenPair(
        int $userId,
        ?string $ip,
        ?string $userAgent,
        ?int $sessionId = null,
        ?string $expectedRefreshHash = null,
    ): array {
        $secret = (string) Config::get('jwt_secret');
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new UnauthorizedException('User not found.', 'USER_NOT_FOUND', 'errors.auth.userNotFound');
        }

        $accessTtl = (int) Config::get('jwt_access_ttl_sec', 900);
        $refreshTtl = (int) Config::get('jwt_refresh_ttl_sec', 2_592_000);

        $accessToken = Jwt::encode(
            ['sub' => (string) $userId, 'role' => $user['role']],
            $accessTtl,
            $secret
        );

        $refreshToken = bin2hex(random_bytes(32));
        $refreshHash = hash('sha256', $refreshToken);
        $accessHash = hash('sha256', $accessToken);

        if ($sessionId === null) {
            $sessionId = $this->sessions->create(
                $userId,
                $refreshHash,
                $accessHash,
                $ip,
                $userAgent,
                $refreshTtl
            );
        } else {
            if ($expectedRefreshHash === null || !$this->sessions->rotate(
                $sessionId,
                $expectedRefreshHash,
                $refreshHash,
                $accessHash,
                $ip,
                $userAgent,
                $refreshTtl,
            )) {
                throw new UnauthorizedException('Invalid token.', 'INVALID_TOKEN', 'errors.auth.invalidToken');
            }
        }

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresIn' => $accessTtl,
            'tokenType' => 'Bearer',
            'user' => $this->publicUser($user),
        ];
    }

    public static function encryptSecret(string $plaintext): string
    {
        return Crypto::encrypt($plaintext);
    }

    public static function decryptSecret(string $payload): string
    {
        return Crypto::decrypt($payload);
    }
}
