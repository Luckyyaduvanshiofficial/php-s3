<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

/**
 * Build a Request for unit tests without touching a real server:
 * feeds $_SERVER and goes through the production fromGlobals() path,
 * so header parsing / segment-wise path decoding are covered too.
 *
 * @param array<string, string> $headers lowercased or normal-cased (HTTP_ mapping is applied)
 */
function php_s3_test_request(
    string $method,
    string $uri,
    array $headers = [],
    string $remoteAddr = '127.0.0.1',
    bool $isHttps = false,
): PhpS3\Http\Request {
    $qpos = strpos($uri, '?');
    $rawPath = $qpos === false ? $uri : substr($uri, 0, $qpos);

    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['REMOTE_ADDR'] = $remoteAddr;
    $_SERVER['REQUEST_SCHEME'] = $isHttps ? 'https' : 'http';
    unset($_SERVER['HTTPS'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

    foreach ($_SERVER as $k => $v) {
        if (str_starts_with((string) $k, 'HTTP_') || in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
            unset($_SERVER[$k]);
        }
    }
    foreach ($headers as $name => $value) {
        $n = strtoupper(str_replace('-', '_', $name));
        if (!in_array($n, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
            $n = 'HTTP_' . $n;
        }
        $_SERVER[$n] = $value;
    }

    return PhpS3\Http\Request::fromGlobals();
}
