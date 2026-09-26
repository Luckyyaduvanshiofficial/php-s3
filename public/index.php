<?php

declare(strict_types=1);

/**
 * Copyright 2026 codaipro — Lucky Yaduvanshi (https://luckyyaduvanshi.in)
 * Original source: https://github.com/Luckyyaduvanshiofficial/php-s3
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 *
 * php-s3 front controller.
 * Everything S3-related and every admin route flows through this file.
 * The data root and config live OUTSIDE the document root (see installer).
 */

if (function_exists('ob_end_clean')) {
    while (ob_get_level() > 0) {
        @ob_end_clean(); // streaming responses must never be buffered
    }
}

// Two supported layouts: dev has the front controller in public/ (project
// root one level up); flat shared-hosting deployments put index.php next to
// src/ because hosts only serve the domain document root.
$bootstrap = is_file(__DIR__ . '/../src/bootstrap.php')
    ? __DIR__ . '/../src/bootstrap.php'
    : __DIR__ . '/src/bootstrap.php';
require $bootstrap;

use PhpS3\Http\Request;
use PhpS3\Http\Response;
use PhpS3\Kernel;

$request = Request::fromGlobals();

if (getenv('PHPS3_SIG_DEBUG')) {
    error_log('REQ ' . $request->method . ' ' . $request->rawPath
        . ($request->queryString !== '' ? '?' . $request->queryString : ''));
}

try {
    $kernel = new Kernel();
    $response = $kernel->handle($request);
    if (getenv('PHPS3_SIG_DEBUG')) {
        error_log('RES ' . $response->status . ' ' . json_encode($response->headers));
    }
    $response->send();
} catch (\Throwable $e) {
    // Last-resort catch: Kernel normally maps S3Exception itself.
    $ctx = php_s3_error_context();
    if ($ctx['log_file'] !== null) {
        \PhpS3\Support\Logger::error(
            'unhandled: ' . $e::class . ': ' . $e->getMessage(),
            ['file' => $e->getFile(), 'line' => $e->getLine()],
            $ctx['log_file'],
        );
    } elseif (in_array(PHP_SAPI, ['cli', 'cli-server'], true) || $ctx['debug']) {
        error_log((string) $e);
    }

    $isS3 = str_starts_with($request->path, '/') && !str_starts_with($request->path, '/_');
    if ($isS3) {
        Response::xml(500, 'InternalError', 'We encountered an internal error. Please try again.', $request->requestId)->send();
    } else {
        Response::text(500, 'Internal Server Error')->send();
    }
}
