<?php

declare(strict_types=1);

/**
 * Controlled VELORA private-runtime migration.
 *
 * This CLI-only tool copies sensitive runtime material outside the document
 * root, verifies SHA-256/size/readability, and can then quarantine the original.
 * It never deletes data and never overwrites a different destination file.
 * Workers/API writes must be stopped before --phase=copy or --phase=quarantine.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', [
    'phase:',
    'private-root:',
    'document-root:',
    'source-root::',
    'quiesced',
    'verified',
]);

$phase = (string) ($options['phase'] ?? 'plan');
$sourceRoot = canonicalDirectory((string) ($options['source-root'] ?? dirname(__DIR__, 2)), 'source root');
$documentRoot = canonicalDirectory((string) ($options['document-root'] ?? $sourceRoot), 'document root');
$privateRootInput = trim((string) ($options['private-root'] ?? ''));
if ($privateRootInput === '' || !isAbsolutePath($privateRootInput)) {
    fail('A valid absolute --private-root is required.');
}

$privateRoot = canonicalCandidate($privateRootInput);
assertOutsideDocumentRoot($privateRoot, $documentRoot);
if (pathIsInside($privateRoot, $sourceRoot)) {
    fail('Private root must not be inside the source tree.');
}

$map = [
    'api/.env' => 'config/velora.env',
    'api/storage/velora.sqlite' => 'data/velora.sqlite',
    'api/storage/mail.log' => 'logs/mail.log',
    'api/error_log' => 'logs/api-error.log',
    '_database/database_corrected.sql' => 'archive/sql/_database/database_corrected.sql',
    'api/database/database.sql' => 'archive/sql/api/database/database.sql',
    'api/database/database_corrected.sql' => 'archive/sql/api/database/database_corrected.sql',
    'api/database/database_fixed.sql' => 'archive/sql/api/database/database_fixed.sql',
    'api/database/db_backup.sql' => 'archive/sql/api/database/db_backup.sql',
];

switch ($phase) {
    case 'plan':
        emit([
            'phase' => 'plan',
            'source_root' => $sourceRoot,
            'document_root' => $documentRoot,
            'private_root' => $privateRoot,
            'files' => inventory($sourceRoot, $privateRoot, $map),
            'next' => 'Stop API/worker writes, then run --phase=copy --quiesced.',
        ]);
        break;

    case 'copy':
        requireFlag($options, 'quiesced', 'Copy requires confirmed quiescence (--quiesced).');
        prepareDirectories($privateRoot);
        $records = [];
        foreach ($map as $sourceRelative => $targetRelative) {
            $source = $sourceRoot . '/' . $sourceRelative;
            if (!is_file($source)) {
                continue;
            }
            rejectSymlink($source, 'source');
            $target = $privateRoot . '/' . $targetRelative;
            copyVerified($source, $target);
            $records[] = recordFor($sourceRelative, $targetRelative, $source, $target);
        }
        if ($records === []) {
            fail('No approved runtime or historical files were found to copy.');
        }
        $manifestPath = $privateRoot . '/archive/runtime-migration-manifest.json';
        writeJsonAtomic($manifestPath, [
            'format' => 1,
            'source_root' => $sourceRoot,
            'document_root' => $documentRoot,
            'private_root' => $privateRoot,
            'records' => $records,
        ], 0600);
        emit([
            'phase' => 'copy',
            'copied_or_verified' => count($records),
            'manifest' => $manifestPath,
            'next' => 'Run --phase=verify and confirm application read/write checks before quarantine.',
        ]);
        break;

    case 'verify':
        $manifest = readManifest($privateRoot);
        $verified = verifyManifest($manifest, false);
        emit([
            'phase' => 'verify',
            'verified' => $verified,
            'readable' => true,
            'checksums_match' => true,
            'next' => 'Switch runtime configuration, test API/mail/cron, then quarantine with --quiesced --verified.',
        ]);
        break;

    case 'quarantine':
        requireFlag($options, 'quiesced', 'Quarantine requires confirmed quiescence (--quiesced).');
        requireFlag($options, 'verified', 'Quarantine requires explicit prior verification (--verified).');
        $manifest = readManifest($privateRoot);
        verifyManifest($manifest, true);
        $stamp = gmdate('Ymd-His');
        $quarantineRoot = $privateRoot . '/quarantine/' . $stamp;
        createPrivateDirectory($quarantineRoot);
        $moved = [];
        foreach ($manifest['records'] as $record) {
            $sourceRelative = safeRelative((string) $record['source']);
            $source = $sourceRoot . '/' . $sourceRelative;
            if (!file_exists($source)) {
                continue;
            }
            rejectSymlink($source, 'source');
            $destination = $quarantineRoot . '/' . $sourceRelative;
            createPrivateDirectory(dirname($destination));
            if (file_exists($destination)) {
                fail('Quarantine destination already exists: ' . $sourceRelative);
            }
            if (!rename($source, $destination)) {
                fail('Could not quarantine: ' . $sourceRelative);
            }
            chmod($destination, 0600);
            $moved[] = $sourceRelative;
        }
        emit([
            'phase' => 'quarantine',
            'quarantine_root' => $quarantineRoot,
            'moved' => $moved,
            'deleted' => 0,
            'next' => 'Retain quarantine through release observation and rollback window.',
        ]);
        break;

    default:
        fail('Unsupported --phase. Use plan, copy, verify, or quarantine.');
}

function inventory(string $sourceRoot, string $privateRoot, array $map): array
{
    $items = [];
    foreach ($map as $sourceRelative => $targetRelative) {
        $source = $sourceRoot . '/' . $sourceRelative;
        $items[] = [
            'source' => $sourceRelative,
            'target' => $targetRelative,
            'present' => is_file($source),
            'size' => is_file($source) ? filesize($source) : null,
            'target_present' => is_file($privateRoot . '/' . $targetRelative),
        ];
    }
    return $items;
}

function prepareDirectories(string $privateRoot): void
{
    foreach (['', 'config', 'data', 'logs', 'archive', 'archive/sql', 'quarantine'] as $relative) {
        createPrivateDirectory(rtrim($privateRoot . '/' . $relative, '/'));
    }
}

function createPrivateDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        fail('Could not create private directory.');
    }
    if (!chmod($path, 0700)) {
        fail('Could not apply private directory mode.');
    }
}

function copyVerified(string $source, string $target): void
{
    createPrivateDirectory(dirname($target));
    $sourceHash = hash_file('sha256', $source);
    $sourceSize = filesize($source);
    if ($sourceHash === false || $sourceSize === false) {
        fail('Could not read approved source file.');
    }

    if (is_file($target)) {
        rejectSymlink($target, 'target');
        if (!hash_equals($sourceHash, (string) hash_file('sha256', $target)) || filesize($target) !== $sourceSize) {
            fail('Refusing to overwrite a different private target: ' . basename($target));
        }
        chmod($target, 0600);
        return;
    }

    $temporary = $target . '.partial-' . getmypid();
    $input = fopen($source, 'rb');
    $output = fopen($temporary, 'xb');
    if ($input === false || $output === false) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
        @unlink($temporary);
        fail('Could not open copy streams.');
    }
    chmod($temporary, 0600);
    $copied = stream_copy_to_stream($input, $output);
    fflush($output);
    if (function_exists('fsync')) {
        fsync($output);
    }
    fclose($input);
    fclose($output);
    if ($copied !== $sourceSize || !hash_equals($sourceHash, (string) hash_file('sha256', $temporary))) {
        @unlink($temporary);
        fail('Copied file failed size/checksum verification.');
    }
    if (!rename($temporary, $target)) {
        @unlink($temporary);
        fail('Could not atomically install private file.');
    }
    chmod($target, 0600);
}

function recordFor(string $sourceRelative, string $targetRelative, string $source, string $target): array
{
    return [
        'source' => $sourceRelative,
        'target' => $targetRelative,
        'bytes' => filesize($source),
        'sha256' => hash_file('sha256', $source),
        'target_sha256' => hash_file('sha256', $target),
    ];
}

function readManifest(string $privateRoot): array
{
    $path = $privateRoot . '/archive/runtime-migration-manifest.json';
    $raw = is_file($path) ? file_get_contents($path) : false;
    $manifest = $raw === false ? null : json_decode($raw, true);
    if (!is_array($manifest) || ($manifest['format'] ?? null) !== 1 || !is_array($manifest['records'] ?? null)) {
        fail('Private runtime migration manifest is missing or invalid.');
    }
    return $manifest;
}

function verifyManifest(array $manifest, bool $requireSources): int
{
    $sourceRoot = canonicalDirectory((string) $manifest['source_root'], 'manifest source root');
    $privateRoot = canonicalDirectory((string) $manifest['private_root'], 'manifest private root');
    $verified = 0;
    foreach ($manifest['records'] as $record) {
        $sourceRelative = safeRelative((string) ($record['source'] ?? ''));
        $targetRelative = safeRelative((string) ($record['target'] ?? ''));
        $source = $sourceRoot . '/' . $sourceRelative;
        $target = $privateRoot . '/' . $targetRelative;
        if (!is_file($target) || !is_readable($target)) {
            fail('Private target is missing or unreadable: ' . $targetRelative);
        }
        rejectSymlink($target, 'target');
        $expectedHash = (string) ($record['sha256'] ?? '');
        $expectedBytes = (int) ($record['bytes'] ?? -1);
        if (!hash_equals($expectedHash, (string) hash_file('sha256', $target)) || filesize($target) !== $expectedBytes) {
            fail('Private target verification failed: ' . $targetRelative);
        }
        if ($requireSources || is_file($source)) {
            if (!is_file($source) || !is_readable($source) ||
                !hash_equals($expectedHash, (string) hash_file('sha256', $source)) ||
                filesize($source) !== $expectedBytes) {
                fail('Source changed after copy: ' . $sourceRelative);
            }
        }
        $verified++;
    }
    return $verified;
}

function writeJsonAtomic(string $path, array $data, int $mode): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $temporary = $path . '.partial-' . getmypid();
    if (file_put_contents($temporary, $json, LOCK_EX) === false) {
        fail('Could not write migration manifest.');
    }
    chmod($temporary, $mode);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        fail('Could not atomically install migration manifest.');
    }
}

function canonicalDirectory(string $path, string $label): string
{
    $resolved = realpath($path);
    if ($resolved === false || !is_dir($resolved)) {
        fail($label . ' must exist and be a directory.');
    }
    return rtrim(str_replace('\\', '/', $resolved), '/');
}

function canonicalCandidate(string $path): string
{
    $resolved = realpath($path);
    if ($resolved !== false) {
        return rtrim(str_replace('\\', '/', $resolved), '/');
    }
    $parent = realpath(dirname($path));
    if ($parent === false) {
        fail('Private root parent must already exist.');
    }
    return rtrim(str_replace('\\', '/', $parent), '/') . '/' . basename($path);
}

function assertOutsideDocumentRoot(string $path, string $documentRoot): void
{
    if (pathIsInside($path, $documentRoot)) {
        fail('Private root resolves inside the document root.');
    }
}

function pathIsInside(string $path, string $parent): bool
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $parent = rtrim(str_replace('\\', '/', $parent), '/');
    return $path === $parent || str_starts_with($path . '/', $parent . '/');
}

function safeRelative(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || str_starts_with($path, '/') || preg_match('~(^|/)\.\.(/|$)~', $path)) {
        fail('Manifest contains an unsafe relative path.');
    }
    return $path;
}

function rejectSymlink(string $path, string $label): void
{
    if (is_link($path)) {
        fail(ucfirst($label) . ' symlinks are not accepted by the migration procedure.');
    }
}

function isAbsolutePath(string $path): bool
{
    return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
}

function requireFlag(array $options, string $name, string $message): void
{
    if (!array_key_exists($name, $options)) {
        fail($message);
    }
}

function emit(array $payload): never
{
    fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}

function fail(string $message): never
{
    fwrite(STDERR, 'ERROR: ' . $message . "\n");
    exit(1);
}
