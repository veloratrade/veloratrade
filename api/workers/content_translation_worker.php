<?php

declare(strict_types=1);

/**
 * Asynchronous dynamic-content translation worker.
 *
 * Run from cron after content ingestion has queued jobs:
 *   php /path/to/api/workers/content_translation_worker.php --max=20
 *
 * The public lookup endpoint never invokes this worker and never enqueues work.
 * Original-language content therefore remains independent of provider latency.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Velora\Core\Locale\ContentTranslationRepository;
use Velora\Core\Locale\TranslationProviderClient;

$options = getopt('', ['max::']);
$max = max(1, min(100, (int) ($options['max'] ?? 10)));
$provider = new TranslationProviderClient();
if (!$provider->isConfigured()) {
    fwrite(STDERR, "Translation worker is disabled: TRANSLATION_SERVICE_URL is not configured.\n");
    exit(2);
}

$repository = ContentTranslationRepository::fromDatabase();
$workerId = substr((gethostname() ?: 'worker') . ':' . getmypid(), 0, 96);
$processed = 0;
$failed = 0;

for ($index = 0; $index < $max; $index++) {
    $job = $repository->claim($workerId);
    if ($job === null) {
        break;
    }
    try {
        $fields = $provider->translate(
            (string) $job['source_locale'],
            (string) $job['target_locale'],
            (array) $job['source_fields'],
        );
        $repository->complete($job, $fields, $provider->name());
        $processed++;
    } catch (Throwable $error) {
        $repository->fail($job, $error->getMessage());
        $failed++;
        fwrite(STDERR, '[' . gmdate('c') . '] Translation job ' . (int) $job['id'] . " failed.\n");
    }
}

echo json_encode([
    'processed' => $processed,
    'failed' => $failed,
    'worker' => $workerId,
    'timestamp' => gmdate('c'),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed > 0 ? 1 : 0);
