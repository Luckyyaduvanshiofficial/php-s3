<?php

declare(strict_types=1);

/**
 * mini-s3 front controller.
 *
 * Everything S3-related and every admin route flows through this file.
 * The data root and config live OUTSIDE the document root (see installer).
 */

if (function_exists('ob_end_clean')) {
    while (ob_get_level() > 0) {
        @ob_end_clean(); // streaming responses must never be buffered
    }
}

require __DIR__ . '/../src/bootstrap.php';

use MiniS3\Http\Request;
use MiniS3\Http\Response;
use MiniS3\Kernel;

$request = Request::fromGlobals();

if (getenv('MINIS3_SIG_DEBUG')) {
    error_log('REQ ' . $request->method . ' ' . $request->rawPath
        . ($request->queryString !== '' ? '?' . $request->queryString : ''));
}

try {
    $kernel = new Kernel();
    $response = $kernel->handle($request);
    if (getenv('MINIS3_SIG_DEBUG')) {
        error_log('RES ' . $response->status . ' ' . json_encode($response->headers));
    }
    $response->send();
} catch (\Throwable $e) {
    // Last-resort catch: Kernel normally maps S3Exception itself.
    $ctx = minis3_error_context();
    if ($ctx['log_file'] !== null) {
        \MiniS3\Support\Logger::error(
            'unhandled: ' . $e::class . ': ' . $e->getMessage(),
            ['file' => $e->getFile(), 'line' => $e->getLine()],
            $ctx['log_file'],
        );
    } elseif (in_array(PHP_SAPI, ['cli', 'cli-server'], true) || $ctx['debug']) {
        error_log((string) $e);
    }

    $isS3 = str_starts_with($request->path, '/') && !str_starts_with($request->path, '/_');
    if ($isS3) {
        Response::xml(500, 'InternalError', $e->getMessage() === '' ? 'We encountered an internal error.' : 'We encountered an internal error.', $request->requestId)->send();
    } else {
        Response::text(500, 'Internal Server Error')->send();
    }
}
