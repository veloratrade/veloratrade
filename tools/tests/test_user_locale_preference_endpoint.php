<?php

declare(strict_types=1);

/**
 * PR-04 — saved locale preference endpoint + resolution contract.
 *
 * Contract under test:
 *   1. PATCH /api/v1/auth/me/preferences exists, is authenticated, and persists
 *      a supported locale with provenance 'user' via UserRepository.
 *   2. Unsupported locales are rejected (422 UNSUPPORTED_LOCALE) with no write.
 *   3. A missing locale fails validation.
 *   4. The server-side resolution order is explicit prefix -> saved locale ->
 *      cookie -> browser -> default (locale-router.php), guarded by the refresh
 *      cookie and fail-closed.
 *   5. First-visit browser detection: fa -> fa; any other, unsupported, or
 *      unknown browser locale -> manifest fallback (English). Explicit URL
 *      locale and the saved user preference are never overridden.
 *   6. The client persists manual switches through the endpoint when signed in.
 *
 * Deterministic: namespace-stubbed Response/Validation/LocaleManager/
 * UserRepository/AuthService + static source-contract checks. No HTTP, no DB,
 * no external services.
 */

// ---- Stubs so AuthController::updatePreferences can be exercised in-process --
namespace Velora\Core {

    final class Request
    {
        public array $body = [];
        public array $attributes = [];
    }

    final class ResponseCaptured extends \Exception
    {
    }

    final class Response
    {
        public static mixed $jsonData = null;
        public static int $jsonStatus = 0;
        public static bool $errorCalled = false;
        public static int $errorStatus = 0;
        public static string $errorCode = '';

        public static function json(mixed $data, int $status = 200): never
        {
            self::$jsonData = $data;
            self::$jsonStatus = $status;
            throw new ResponseCaptured();
        }

        public static function error(
            string $message,
            int $status = 400,
            ?string $code = null,
            mixed $details = null,
            ?string $messageKey = null,
            array $params = [],
        ): never {
            self::$errorCalled = true;
            self::$errorStatus = $status;
            self::$errorCode = (string) $code;
            throw new ResponseCaptured();
        }
    }

    final class Validation
    {
        public static function assert(array $data, array $rules): void
        {
            foreach ($rules as $field => $rule) {
                if (str_contains($rule, 'required') && !array_key_exists($field, $data)) {
                    throw new \RuntimeException('validation: ' . $field . ' required');
                }
            }
        }
    }
}

namespace Velora\Core\Locale {

    final class LocaleManager
    {
        public static function getInstance(): self
        {
            return new self();
        }

        public function supports(string $locale): bool
        {
            $candidate = strtolower(str_replace('_', '-', trim($locale)));
            if ($candidate === 'fa' || $candidate === 'en') {
                return true;
            }
            $base = explode('-', $candidate, 2)[0];
            return $base === 'fa' || $base === 'en';
        }

        public function resolve(string $locale): string
        {
            $candidate = strtolower(str_replace('_', '-', trim($locale)));
            if ($candidate === 'en') {
                return 'en';
            }
            if (str_starts_with($candidate, 'en')) {
                return 'en';
            }
            return 'fa';
        }
    }
}

namespace Velora\Auth {

    final class UserRepository
    {
        /** @var list<array{int,string,string}> */
        public static array $writes = [];

        public function updateLocalePreference(int $userId, string $locale, string $source): bool
        {
            self::$writes[] = [$userId, $locale, $source];
            return true;
        }
    }

    final class AuthService
    {
        public function publicUser(array $user): array
        {
            return $user;
        }
    }
}

namespace {

    require dirname(__DIR__, 2) . '/api/src/Auth/AuthController.php';

    use Velora\Core\Request;
    use Velora\Core\Response;
    use Velora\Core\ResponseCaptured;
    use Velora\Auth\UserRepository;

    $repoRoot = dirname(__DIR__, 2);
    $assertions = 0;
    $failures = [];
    $check = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
        $assertions++;
        if (!$condition) {
            $failures[] = $message;
            fwrite(STDERR, "FAIL: {$message}\n");
        }
    };

    $controller = new \Velora\Auth\AuthController();

    // ---- Pin 1: happy path persists canonical fa with provenance 'user' ------
    $req = new Request();
    $req->body = ['locale' => 'fa-IR'];
    $req->attributes = ['user_id' => 7];
    try {
        $controller->updatePreferences($req);
        $check(false, 'updatePreferences must respond through Response');
    } catch (ResponseCaptured) {
        $check(Response::$jsonStatus === 200, 'happy path responds 200');
        $check((Response::$jsonData['updated'] ?? false) === true, 'happy path reports updated=true');
        $check((Response::$jsonData['locale'] ?? null) === 'fa', 'regional fa-IR normalizes to canonical fa');
        $check(
            UserRepository::$writes === [[7, 'fa', 'user']],
            'repository write is (user_id=7, locale=fa, source=user)',
        );
    }

    // ---- Pin 2: unsupported locale rejected with no write --------------------
    UserRepository::$writes = [];
    Response::$errorCalled = false;
    Response::$errorStatus = 0;
    Response::$errorCode = '';
    $req2 = new Request();
    $req2->body = ['locale' => 'de'];
    $req2->attributes = ['user_id' => 7];
    try {
        $controller->updatePreferences($req2);
        $check(false, 'unsupported locale must respond through error path');
    } catch (ResponseCaptured) {
        $check(Response::$errorCalled, 'unsupported locale uses the error path');
        $check(Response::$errorStatus === 422, 'unsupported locale returns 422');
        $check(Response::$errorCode === 'UNSUPPORTED_LOCALE', 'unsupported locale error code is UNSUPPORTED_LOCALE');
        $check(UserRepository::$writes === [], 'unsupported locale performs no repository write');
    }

    // ---- Pin 3: missing locale fails validation ------------------------------
    UserRepository::$writes = [];
    $req3 = new Request();
    $req3->body = [];
    $req3->attributes = ['user_id' => 7];
    try {
        $controller->updatePreferences($req3);
        $check(false, 'missing locale must fail validation');
    } catch (\RuntimeException $e) {
        $check(str_contains($e->getMessage(), 'required'), 'missing locale raises a validation error');
        $check(UserRepository::$writes === [], 'validation failure performs no repository write');
    }

    // ---- Pin 4: route registered with auth middleware ------------------------
    $indexLines = (array) file($repoRoot . '/api/index.php');
    $routeWired = false;
    foreach ($indexLines as $line) {
        if (str_contains($line, 'me/preferences')) {
            $routeWired = str_contains($line, 'PATCH')
                && str_contains($line, 'AuthController')
                && str_contains($line, 'updatePreferences')
                && str_contains($line, '$auth');
        }
    }
    $check($routeWired, 'PATCH /api/v1/auth/me/preferences is registered with auth middleware');

    // ---- Pin 5: server resolution order (saved locale > cookie > browser) ----
    $routerSrc = (string) file_get_contents($repoRoot . '/locale-router.php');
    $check(
        str_contains($routerSrc, '$savedLocale ?? $cookieLocale ?? $browserLocale ?? $default'),
        'resolution order places saved locale above cookie/browser/default',
    );
    $check(
        str_contains($routerSrc, '__Host-velora_refresh'),
        'saved-locale lookup is guarded by the refresh cookie',
    );
    $check(
        str_contains($routerSrc, 'SELECT locale FROM users'),
        'saved-locale read selects users.locale in a separate, tolerant step',
    );
    $check(
        str_contains($routerSrc, '$normalize($primaryTag) ?? $fallback'),
        'unsupported browser locale resolves to the manifest fallback (en)',
    );
    $check(
        str_contains($routerSrc, ': $fallback;'),
        'unknown/absent/wildcard browser locale resolves to the manifest fallback (en)',
    );
    $check(
        !str_contains($routerSrc, '$browserLocale = null;'),
        'browser tier never falls through to the default locale when unknown',
    );

    // ---- Pin 6: client persists the manual switch via the endpoint -----------
    $locJs = (string) file_get_contents($repoRoot . '/public/assets/velora-localization.js');
    $check(
        str_contains($locJs, '/api/v1/auth/me/preferences'),
        'client setLocale() persists the manual choice through the endpoint',
    );
    $check(
        str_contains($locJs, 'getAccessToken'),
        'client uses the in-memory access token (VeloraData.getAccessToken)',
    );

    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    if ($failures !== []) {
        fwrite(STDERR, 'PR-04 TEST FAILED: ' . count($failures) . "/{$assertions} assertions failed\n");
        exit(1);
    }
    echo "PR-04 saved-locale endpoint contract: PASS ({$assertions} assertions)\n";
    exit(0);
}
