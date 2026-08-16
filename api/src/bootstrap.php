<?php

declare(strict_types=1);

/**
 * Exact decimal arithmetic is a startup requirement. Financial values must
 * never fall back to binary floating point when ext-bcmath is unavailable or
 * incomplete on a host.
 */
$veloraRequiredBcmathFunctions = ['bcadd', 'bcsub', 'bcmul', 'bcdiv', 'bccomp'];
$veloraMissingBcmathFunctions = array_values(array_filter(
    $veloraRequiredBcmathFunctions,
    static fn (string $function): bool => !function_exists($function),
));
if (!extension_loaded('bcmath') || $veloraMissingBcmathFunctions !== []) {
    $veloraStartupError = 'VELORA startup refused: ext-bcmath with bcadd, bcsub, bcmul, bcdiv, and bccomp is required for exact decimal arithmetic.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $veloraStartupError . PHP_EOL);
        exit(1);
    }

    error_log($veloraStartupError);
    http_response_code(503);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode([
        'status' => 'error',
        'data' => null,
        'error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'Service unavailable.'],
        'timestamp' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);
    exit(1);
}
unset($veloraRequiredBcmathFunctions, $veloraMissingBcmathFunctions);

/**
 * PSR-4 style autoloader for the Velora\ namespace.
 * Dependency-free — no Composer required (works on any cPanel host).
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Velora\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

// Global exception handler → standardized JSON error contract.
set_exception_handler(static function (\Throwable $e): void {
    $isApi = $e instanceof \Velora\Core\Exceptions\ApiException;

    $status = $isApi ? $e->httpStatus() : 500;
    $message = $isApi ? $e->getMessage() : 'Internal server error.';
    $code = $isApi ? $e->errorCode() : 'INTERNAL_ERROR';
    $details = $isApi ? $e->details() : null;

    if (!$isApi && \Velora\Core\Config::get('app_debug', false)) {
        $message = $e->getMessage();
        $details = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }

    $messageKey = $isApi ? $e->messageKey() : 'errors.http.500';
    $params = $isApi ? $e->params() : [];
    \Velora\Core\Response::error($message, $status, $code, $details, $messageKey, $params);
});
