<?php

declare(strict_types=1);

/**
 * Velora AI Jobs Worker — async processing for PHP-FPM (no Redis).
 * Uses MySQL lease pattern like metaapi_sync_worker.php (fenced queue).
 *
 * Usage:
 *   php api/workers/ai_job_worker.php --max=10
 *   php api/workers/ai_job_worker.php --max=20 --type=analysis
 *
 * Cron: */5 * * * * php /path/to/api/workers/ai_job_worker.php --max=10 >> /path/to/logs/ai_jobs.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Velora\AI\Analysis\TradeAnalyzerService;
use Velora\AI\Jobs\AIJobRepository;
use Velora\AI\Reports\WeeklyReportService;

$options = getopt('', ['max::', 'type::']);
$max = max(1, min(100, (int) ($options['max'] ?? 10)));
$jobType = $options['type'] ?? null;

$repository = new AIJobRepository();
$workerId = substr((gethostname() ?: 'worker') . ':ai-' . getmypid(), 0, 96);

$processed = 0;
$failed = 0;
$completed = 0;

echo '[' . gmdate('c') . "] AI job worker tick (worker=$workerId, max=$max, type=" . ($jobType ?? 'any') . ")\n";

for ($i = 0; $i < $max; $i++) {
    $job = $repository->claimJob($workerId, $jobType);
    if ($job === null) {
        echo "No claimable jobs.\n";
        break;
    }

    $jobId = (int) $job['id'];
    $userId = (int) $job['user_id'];
    $type = $job['job_type'];
    $payload = $job['payload'] ?? [];

    echo sprintf("Processing job %d type=%s user=%d attempt=%d\n", $jobId, $type, $userId, $job['attempts'] ?? 1);

    try {
        $result = [];
        if ($type === 'analysis' || $type === 'trade_analysis') {
            $service = new TradeAnalyzerService();
            $trades = $payload['trades'] ?? [];
            $response = $service->analyze($userId, $trades, [
                'locale' => $payload['locale'] ?? 'en',
                'timeframe' => $payload['timeframe'] ?? 'last_100',
            ]);
            $result = ['analysis' => json_decode($response->content, true) ?: $response->content];
        } elseif ($type === 'report' || $type === 'weekly_report') {
            $service = new WeeklyReportService();
            $trades = $payload['trades'] ?? [];
            $weekStart = $payload['period_start'] ?? $payload['week_start'] ?? gmdate('Y-m-d', strtotime('last monday'));
            $response = $service->generateWeekly($userId, $weekStart, [
                'locale' => $payload['locale'] ?? 'en',
                'trades' => $trades,
            ]);
            $result = ['report' => json_decode($response->content, true) ?: $response->content];
        } elseif ($type === 'extraction' || $type === 'screenshot_extraction') {
            $result = ['extraction' => 'async extraction not yet fully implemented, use sync endpoint'];
        } else {
            $result = ['handled' => true, 'type' => $type, 'note' => 'generic job'];
        }

        $repository->completeJob($jobId, $result);
        $completed++;
        $processed++;
        echo sprintf("Completed job %d\n", $jobId);
    } catch (Throwable $e) {
        $failed++;
        $processed++;
        $errorCode = $e instanceof \Velora\AI\Exceptions\AIException ? $e->errorCode() : 'FAILED';
        $delay = 60 * (int) ($job['attempts'] ?? 1);
        $repository->failJob($jobId, $errorCode, $delay);
        fwrite(STDERR, '[' . gmdate('c') . "] Job $jobId failed: " . $e->getMessage() . "\n");
        error_log('[VELORA_AI_JOBS] job ' . $jobId . ' failed: ' . $e->getMessage());
    }
}

echo json_encode([
    'processed' => $processed,
    'completed' => $completed,
    'failed' => $failed,
    'worker' => $workerId,
    'timestamp' => gmdate('c'),
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failed > 0 ? 1 : 0);
