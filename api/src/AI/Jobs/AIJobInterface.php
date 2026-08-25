<?php

declare(strict_types=1);

namespace Velora\AI\Jobs;

/**
 * P1 placeholder — Async AI jobs (no Redis, DB queue like metaapi_sync_worker).
 * For future: weekly reports, analysis, assistant — when sync 30s not enough.
 */
interface AIJobInterface
{
    public function getType(): string; // extraction, analysis, report, assistant

    public function getUserId(): int;

    /**
     * @return array<string,mixed> Payload for worker
     */
    public function getPayload(): array;

    /**
     * Execute job — called by worker.
     *
     * @return array<string,mixed> Result
     */
    public function handle(): array;

    public function getMaxAttempts(): int;

    public function getTimeoutSeconds(): int;
}
