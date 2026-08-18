<?php

declare(strict_types=1);

namespace Velora\Auth;

use Velora\Core\Config;
use Velora\Core\Exceptions\ForbiddenException;
use Velora\Core\Exceptions\UnauthorizedException;
use Velora\Core\RateLimiter;
use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Core\Validation;

/**
 * HTTP layer for /api/v1/auth/* — thin controllers, all logic in AuthService
 * (CTO checklist #1: no direct DB writes in controllers).
 */
final class AuthController
{
    public function __construct(
        private readonly AuthService $service = new AuthService(),
    ) {
    }

    public function register(Request $request): never
    {
        Validation::assert($request->body, [
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8|max:72',
            'full_name' => 'string|max:120',
            'fullName' => 'string|max:120',
            'timezone' => 'string|max:64',
            'notificationLocale' => 'string|max:35',
        ]);

        $result = $this->service->register(
            $request->body,
            self::clientIp(),
            $request->headers['user-agent'] ?? null,
        );

        // Registration never issues a session: the account stays unverified
        // until the emailed token is redeemed. Passing this response to
        // respondWithSession() would throw (no refresh credential) and surface
        // as HTTP 500, so the verification contract is returned as-is.
        if (!empty($result['verificationRequired'])) {
            Response::json($result, 201);
        }

        $this->respondWithSession($result, 201);
    }

    /** تأیید توکن ایمیل از لینک ارسال‌شده به کاربر. */
    public function verifyEmail(Request $request): never
    {
        Validation::assert($request->body, [
            'token' => 'required|string|max:128',
            'notificationLocale' => 'string|max:35',
        ]);

        $alreadyVerified = $this->service->verifyEmail(
            (string) $request->body['token'],
            (string) ($request->body['notificationLocale'] ?? ''),
        );
        Response::json([
            'verified' => true,
            'alreadyVerified' => $alreadyVerified,
            'messageKey' => $alreadyVerified ? 'auth.emailAlreadyVerified' : 'auth.emailVerified',
            'params' => (object) [],
        ]);
    }

    /** ارسال مجدد ایمیل تأیید برای حساب‌های تأییدنشده یا لینک‌های منقضی‌شده پس از ۲۴ ساعت. */
    public function resendVerification(Request $request): never
    {
        Validation::assert($request->body, [
            'email' => 'required|string|email|max:255',
            'notificationLocale' => 'string|max:35',
        ]);

        $result = $this->service->resendVerification($request->body);
        Response::json($result, 200);
    }

    public function login(Request $request): never
    {
        Validation::assert($request->body, [
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|max:72',
            'notificationLocale' => 'string|max:35',
        ]);

        $result = $this->service->login(
            $request->body,
            self::clientIp(),
            $request->headers['user-agent'] ?? null,
        );

        $this->respondWithSession($result, 200);
    }

    public function refresh(Request $request): never
    {
        $refreshToken = $this->refreshTokenFromRequest($request);
        try {
            $result = $this->service->refresh(
                $refreshToken,
                self::clientIp(),
                $request->headers['user-agent'] ?? null,
            );
        } catch (UnauthorizedException $exception) {
            Response::clearRefreshCookie();
            throw $exception;
        }

        $this->respondWithSession($result, 200);
    }

    public function logout(Request $request): never
    {
        // Logout must always expire the browser credential. In particular, an
        // origin/session validation failure must not leave an HttpOnly refresh
        // cookie active and cause the login page to restore the prior session.
        try {
            $refreshToken = $this->refreshTokenFromRequest($request, true);
            if ($refreshToken !== null) {
                $this->service->logout($refreshToken);
            }
        } finally {
            Response::clearRefreshCookie();
        }
        Response::json(['loggedOut' => true], 200);
    }

    /** Auth middleware already attached this user; just serialize it. */
    public function me(Request $request): never
    {
        $user = $request->attributes['user'] ?? null;
        if ($user === null) {
            Response::error('Unauthenticated.', 401, 'UNAUTHORIZED');
        }
        Response::json(['user' => $user]);
    }

    // ==================== رمز عبور ====================

    public function changePassword(Request $request): never
    {
        Validation::assert($request->body, [
            'currentPassword' => 'required|string|max:72',
            'newPassword' => 'required|string|min:8|max:72',
            'notificationLocale' => 'string|max:35',
        ]);

        $service = new PasswordService();
        $service->changePassword((int) $request->attributes['user_id'], $request->body);

        Response::json([
            'changed' => true,
            'messageKey' => 'auth.passwordChanged',
            'params' => (object) [],
        ]);
    }

    public function forgotPassword(Request $request): never
    {
        Validation::assert($request->body, [
            'email' => 'required|string|email|max:255',
            'notificationLocale' => 'string|max:35',
        ]);

        $service = new PasswordService();
        $service->forgotPassword(
            (string) $request->body['email'],
            (string) ($request->body['notificationLocale'] ?? ''),
        );

        // همیشه پیام موفق — لینک فقط از طریق ایمیل ارسال می‌شود
        Response::json([
            'sent' => true,
            'messageKey' => 'auth.passwordResetSentIfRegistered',
            'params' => (object) [],
        ]);
    }

    public function resetPassword(Request $request): never
    {
        Validation::assert($request->body, [
            'token' => 'required|string|max:128',
            'newPassword' => 'required|string|min:8|max:72',
            'notificationLocale' => 'string|max:35',
        ]);

        $service = new PasswordService();
        $service->resetPassword($request->body);

        Response::json([
            'reset' => true,
            'messageKey' => 'auth.passwordReset',
            'params' => (object) [],
        ]);
    }

    /** Set the rotated refresh token only as the approved HttpOnly cookie. */
    private function respondWithSession(array $tokens, int $status): never
    {
        $refreshToken = $tokens['refreshToken'] ?? null;
        if (!is_string($refreshToken) || $refreshToken === '') {
            throw new \RuntimeException('Auth service did not issue a refresh credential.');
        }
        unset($tokens['refreshToken']);
        Response::setRefreshCookie(
            $refreshToken,
            (int) Config::get('jwt_refresh_ttl_sec', 2_592_000),
        );
        Response::json(['tokens' => $tokens], $status);
    }

    /**
     * Prefer the HttpOnly cookie. During the approved 168-hour transition only,
     * an existing body token may be exchanged once into the cookie contract.
     */
    private function refreshTokenFromRequest(Request $request, bool $allowMissing = false): ?string
    {
        $cookieToken = $_COOKIE[Response::REFRESH_COOKIE_NAME] ?? null;
        if (is_string($cookieToken) && $cookieToken !== '') {
            $this->assertSameOrigin($request);
            if (strlen($cookieToken) > 128) {
                throw new UnauthorizedException('Invalid token.', 'INVALID_TOKEN', 'errors.auth.invalidToken');
            }
            return $cookieToken;
        }

        $legacyToken = $request->input('refreshToken');
        if (is_string($legacyToken) && $legacyToken !== '') {
            if (!$this->legacyBodyRefreshIsActive()) {
                throw new UnauthorizedException('Legacy refresh exchange has ended.', 'LEGACY_REFRESH_EXPIRED', 'errors.auth.sessionExpired');
            }
            if (strlen($legacyToken) > 128) {
                throw new UnauthorizedException('Invalid token.', 'INVALID_TOKEN', 'errors.auth.invalidToken');
            }
            return $legacyToken;
        }

        if ($allowMissing) {
            return null;
        }
        throw new UnauthorizedException('Refresh cookie is missing.', 'REFRESH_COOKIE_MISSING', 'errors.auth.sessionExpired');
    }

    private function legacyBodyRefreshIsActive(): bool
    {
        if (Config::get('auth.legacy_body_refresh_enabled', false) !== true) {
            return false;
        }
        $activatedAt = (int) Config::get('auth.refresh_cookie_activated_at', 0);
        $cutoffAt = (int) Config::get('auth.legacy_body_refresh_cutoff_at', 0);
        return $activatedAt > 0 && $cutoffAt === ($activatedAt + 604_800) && time() < $cutoffAt;
    }

    /** Cookie-authenticated state changes are accepted only from the canonical origin. */
    private function assertSameOrigin(Request $request): void
    {
        $actual = self::normalizedOrigin((string) ($request->headers['origin'] ?? ''));
        $expected = self::normalizedOrigin((string) Config::get('frontend_url', ''));
        if ($actual === null || $expected === null || !hash_equals($expected, $actual)) {
            throw new ForbiddenException('Same-origin request required.', 'SAME_ORIGIN_REQUIRED', 'errors.forbidden');
        }
    }

    private static function normalizedOrigin(string $value): ?string
    {
        $parts = parse_url(trim($value));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        return $scheme . '://' . $host . (($port !== null && $port !== $defaultPort) ? ':' . $port : '');
    }

    private static function clientIp(): ?string
    {
        return RateLimiter::clientIp();
    }
}
