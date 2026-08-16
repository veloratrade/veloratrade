<?php

declare(strict_types=1);

/**
 * Alternate document-root entry point.
 *
 * The canonical route table, middleware chain, rate limits, localization error
 * contract, and exception handling live in ../index.php. Delegating here keeps
 * deployments that expose api/public from drifting from the intended Apache
 * deployment, which rewrites /api/* to api/index.php.
 */
require dirname(__DIR__) . '/index.php';
