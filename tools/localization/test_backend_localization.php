<?php

declare(strict_types=1);

use Velora\Core\EmailTemplate;
use Velora\Core\Locale\ContentTranslationRepository;
use Velora\Core\Locale\LocaleManager;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Velora\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__, 2) . '/api/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function sqliteConnection(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 10000');
    return $pdo;
}

function createTranslationSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE content_translation_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_type TEXT NOT NULL,
    content_id TEXT NOT NULL,
    source_locale TEXT NOT NULL,
    target_locale TEXT NOT NULL,
    source_hash TEXT NOT NULL,
    translated_fields TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'ready',
    provider TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(content_type, content_id, source_hash, target_locale)
);
CREATE INDEX idx_translation_lookup
    ON content_translation_cache(target_locale, status, content_type, content_id);
CREATE TABLE content_translation_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_type TEXT NOT NULL,
    content_id TEXT NOT NULL,
    source_locale TEXT NOT NULL,
    target_locale TEXT NOT NULL,
    source_hash TEXT NOT NULL,
    source_fields TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    locked_by TEXT NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(content_type, content_id, source_hash, target_locale)
);
CREATE INDEX idx_translation_job_claim
    ON content_translation_jobs(status, available_at, id);
SQL);
}

function testLocaleAndEmail(): void
{
    $locale = LocaleManager::getInstance();
    check($locale->supports('en-GB'), 'enabled base-language resolution failed');
    check(!$locale->supports('de-DE'), 'unsupported locale was accepted');
    check($locale->resolve('en-GB') === 'en', 'regional English did not resolve to enabled English');
    check($locale->resolve('de-DE') === 'en', 'unsupported locale did not resolve to manifest fallback English');
    check($locale->directionFor('fa') === 'rtl', 'Persian direction must be RTL');
    check($locale->directionFor('en') === 'ltr', 'English direction must be LTR');

    $locale->setLanguage('fa');
    $english = $locale->translateFor('en', 'email.common.copyright', ['year' => 2030]);
    check($locale->getLanguage() === 'fa', 'translateFor mutated global locale state');
    check(str_contains($english, '2030'), 'email placeholder was not interpolated');

    $fa = EmailTemplate::render(
        $locale->translateFor('fa', 'email.verification.badge'),
        $locale->translateFor('fa', 'email.verification.title'),
        '<p>محتوا</p>',
        $locale->translateFor('fa', 'email.verification.cta'),
        'https://example.test/verify?a=1&b=2',
        $locale->translateFor('fa', 'email.verification.notice'),
        null,
        'fa',
    );
    $en = EmailTemplate::render(
        $locale->translateFor('en', 'email.verification.badge'),
        $locale->translateFor('en', 'email.verification.title'),
        '<p>Content</p>',
        $locale->translateFor('en', 'email.verification.cta'),
        'https://example.test/verify?a=1&b=2',
        $locale->translateFor('en', 'email.verification.notice'),
        null,
        'en',
    );
    check(str_contains($fa, 'lang="fa" dir="rtl"'), 'Persian email shell has wrong language/direction');
    check(str_contains($fa, 'border-right:4px'), 'Persian notice border was not mirrored');
    check(str_contains($en, 'lang="en" dir="ltr"'), 'English email shell has wrong language/direction');
    check(str_contains($en, 'border-left:4px'), 'English notice border was not mirrored');
    check(str_contains($en, 'a=1&amp;b=2'), 'email action URL was not escaped');
    check(!str_contains($fa, 'email.common.') && !str_contains($en, 'email.common.'), 'raw email key leaked into output');
    check($fa !== $en, 'recipient-locale email renders are unexpectedly identical');
}

function testQueueLifecycleAndRace(): void
{
    $path = sys_get_temp_dir() . '/velora-translation-' . bin2hex(random_bytes(6)) . '.sqlite';
    try {
        $pdo = sqliteConnection($path);
        $pdo->exec('PRAGMA journal_mode = WAL');
        createTranslationSchema($pdo);
        $repository = new ContentTranslationRepository($pdo);

        // Dedupe by content/version/target locale.
        $repository->enqueue('news', 'dedupe', 'fa', 'en', 'hash-dedupe', ['title' => 'اصل']);
        $repository->enqueue('news', 'dedupe', 'fa', 'en', 'hash-dedupe', ['title' => 'اصل']);
        check((int) $pdo->query("SELECT COUNT(*) FROM content_translation_jobs WHERE content_id='dedupe'")->fetchColumn() === 1, 'enqueue dedupe failed');
        $dedupeJob = $repository->claim('lifecycle-worker');
        check($dedupeJob !== null && $dedupeJob['attempts'] === 1, 'job claim did not increment attempts');
        $repository->complete($dedupeJob, ['title' => 'Original translated'], 'test');
        $ready = $repository->lookupReady('en', [[
            'contentType' => 'news', 'contentId' => 'dedupe', 'sourceHash' => 'hash-dedupe',
        ]]);
        check(count($ready) === 1 && $ready[0]['fields']['title'] === 'Original translated', 'completed cache lookup failed');
        check($repository->lookupReady('en', [[
            'contentType' => 'news', 'contentId' => 'dedupe', 'sourceHash' => 'different-hash',
        ]]) === [], 'source-hash cache version fence failed');

        // Retry exhaustion.
        $repository->enqueue('news', 'retry', 'fa', 'en', 'hash-retry', ['title' => 'اصل']);
        $pdo->exec("UPDATE content_translation_jobs SET max_attempts=2 WHERE content_id='retry'");
        $retryOne = $repository->claim('retry-worker');
        check($retryOne !== null, 'first retry job claim failed');
        $repository->fail($retryOne, 'first failure');
        $pdo->exec("UPDATE content_translation_jobs SET available_at='2000-01-01 00:00:00' WHERE content_id='retry'");
        $retryTwo = $repository->claim('retry-worker');
        check($retryTwo !== null && $retryTwo['attempts'] === 2, 'second retry attempt was not claimed');
        $repository->fail($retryTwo, 'final failure');
        $retryRow = $pdo->query("SELECT status, attempts, locked_by FROM content_translation_jobs WHERE content_id='retry'")->fetch();
        check($retryRow['status'] === 'failed' && (int) $retryRow['attempts'] === 2 && $retryRow['locked_by'] === null, 'retry exhaustion state is invalid');

        // Lease recovery and stale-owner write fence.
        $repository->enqueue('news', 'lease', 'fa', 'en', 'hash-lease', ['title' => 'اصل']);
        $oldClaim = $repository->claim('old-worker');
        check($oldClaim !== null, 'old worker did not claim lease test job');
        $pdo->exec("UPDATE content_translation_jobs SET locked_at='2000-01-01 00:00:00' WHERE content_id='lease'");
        $newClaim = $repository->claim('new-worker');
        check($newClaim !== null && $newClaim['locked_by'] === 'new-worker' && $newClaim['attempts'] === 2, 'stale lease was not recovered/reclaimed');
        $lostLease = false;
        try {
            $repository->complete($oldClaim, ['title' => 'Stale translation'], 'stale-worker');
        } catch (RuntimeException $error) {
            $lostLease = str_contains($error->getMessage(), 'lease was lost');
        }
        check($lostLease, 'stale worker completion was not rejected');
        check($repository->lookupReady('en', [[
            'contentType' => 'news', 'contentId' => 'lease', 'sourceHash' => 'hash-lease',
        ]]) === [], 'stale worker wrote to cache');
        $repository->complete($newClaim, ['title' => 'Current translation'], 'new-worker');

        // Multi-process race: every unique job must be claimed/completed once.
        $raceJobs = 48;
        for ($index = 0; $index < $raceJobs; $index++) {
            $id = 'race-' . $index;
            $repository->enqueue('news', $id, 'fa', 'en', hash('sha256', $id), ['title' => 'خبر ' . $index]);
        }
        unset($repository, $pdo);

        $children = [];
        $workers = 4;
        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork failed');
            }
            if ($pid === 0) {
                try {
                    $childPdo = sqliteConnection($path);
                    $childRepository = new ContentTranslationRepository($childPdo);
                    $workerId = 'race-worker-' . $worker;
                    while (($job = $childRepository->claim($workerId)) !== null) {
                        if (!str_starts_with((string) $job['content_id'], 'race-')) {
                            continue;
                        }
                        usleep(random_int(500, 4000));
                        $childRepository->complete($job, ['title' => 'Translated ' . $job['content_id']], 'race-test');
                    }
                    exit(0);
                } catch (Throwable $error) {
                    fwrite(STDERR, 'child failure: ' . $error->getMessage() . PHP_EOL . $error->getTraceAsString() . PHP_EOL);
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'race worker exited unsuccessfully');
        }

        $pdo = sqliteConnection($path);
        $done = (int) $pdo->query("SELECT COUNT(*) FROM content_translation_jobs WHERE content_id LIKE 'race-%' AND status='done'")->fetchColumn();
        $cache = (int) $pdo->query("SELECT COUNT(*) FROM content_translation_cache WHERE content_id LIKE 'race-%' AND status='ready'")->fetchColumn();
        $attempts = (int) $pdo->query("SELECT COALESCE(SUM(attempts),0) FROM content_translation_jobs WHERE content_id LIKE 'race-%'")->fetchColumn();
        $owners = (int) $pdo->query("SELECT COUNT(*) FROM content_translation_jobs WHERE content_id LIKE 'race-%' AND locked_by IS NOT NULL")->fetchColumn();
        check($done === $raceJobs, "race completion mismatch: {$done}/{$raceJobs}");
        check($cache === $raceJobs, "race cache mismatch: {$cache}/{$raceJobs}");
        check($attempts === $raceJobs, "race jobs were claimed more than once: attempts={$attempts}");
        check($owners === 0, 'completed race jobs retained lock owners');
    } finally {
        if (isset($path) && is_file($path)) {
            @unlink($path);
            @unlink($path . '-shm');
            @unlink($path . '-wal');
        }
    }
}

try {
    testLocaleAndEmail();
    testQueueLifecycleAndRace();
    echo "BACKEND_LOCALIZATION_TEST_OK locale=true email_rtl_ltr=true queue_lifecycle=true ownership_fence=true race_workers=4 race_jobs=48\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'BACKEND_LOCALIZATION_TEST_FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
