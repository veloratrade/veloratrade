<?php

declare(strict_types=1);

/**
 * Temporary CLI wrapper for cPanel Cron environments that block shell redirects.
 * Upload this file to: api/workers/preflight_write_file.php
 * Run with cron: /usr/local/bin/php /home/piknet/public_html/api/workers/preflight_write_file.php
 * Output is written to: /home/piknet/preflight_v0_2.out
 * Delete this file after collecting the output.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

$outputFile = dirname(__DIR__, 3) . '/preflight_v0_2.out';

ob_start();
register_shutdown_function(static function () use ($outputFile): void {
    $output = ob_get_contents();
    if (!is_string($output) || $output === '') {
        $output = 'No output captured from preflight.' . PHP_EOL;
    }
    @file_put_contents($outputFile, $output);
});

require __DIR__ . '/preflight_v0_2.php';
