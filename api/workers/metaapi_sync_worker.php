<?php

declare(strict_types=1);

/**
 * One fenced MetaApi queue tick.
 * Cron may invoke this concurrently; atomic lease acquisition prevents two
 * workers from owning the same attempt.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Velora\Accounts\MetaApiService;

$workerId = 'metaapi-' . gethostname() . '-' . getmypid();
$service = new MetaApiService();

echo '[' . gmdate('c') . "] MetaApi worker tick.\n";
try {
    $result = $service->runNextSyncJob($workerId);
    if ($result === null) {
        echo "No claimable jobs.\n";
        exit(0);
    }
    echo sprintf(
        "Completed job %d for account %d; inserted %d trade(s).\n",
        $result['job_id'],
        $result['account_id'],
        $result['inserted'],
    );
    exit(0);
} catch (\Throwable $e) {
    // Error bodies and credentials are deliberately omitted from worker output.
    fwrite(STDERR, '[' . gmdate('c') . "] MetaApi worker attempt failed.\n");
    exit(1);
}
