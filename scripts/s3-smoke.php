<?php

declare(strict_types=1);

/**
 * Copyright 2026 codaipro — Lucky Yaduvanshi (https://luckyyaduvanshi.in)
 * Original source: https://github.com/Luckyyaduvanshiofficial/php-s3
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

use Aws\S3\S3Client;

require dirname(__DIR__) . '/vendor/autoload.php';

$endpoint = getenv('PHP_S3_ENDPOINT') ?: '';
$accessKey = getenv('PHP_S3_ACCESS_KEY_ID') ?: '';
$secretKey = getenv('PHP_S3_SECRET_ACCESS_KEY') ?: '';
$region = getenv('PHP_S3_REGION') ?: 'us-east-1';

if ($endpoint === '' || $accessKey === '' || $secretKey === '') {
    fwrite(STDERR, "Set PHP_S3_ENDPOINT, PHP_S3_ACCESS_KEY_ID, and PHP_S3_SECRET_ACCESS_KEY.\n");
    exit(2);
}

$client = new S3Client([
    'version' => 'latest',
    'region' => $region,
    'endpoint' => $endpoint,
    'use_path_style_endpoint' => true,
    'credentials' => ['key' => $accessKey, 'secret' => $secretKey],
]);

$bucket = 'php-s3-smoke-' . bin2hex(random_bytes(8));
$smallKey = 'smoke/round-trip.txt';
$copyKey = 'smoke/copied.txt';
$multipartKey = 'smoke/multipart.bin';
$smallBody = "php-s3 S3 API smoke test\n";
$multipartBody = str_repeat('php-s3-multipart-check-', 250000);
$multipartUploadId = null;
$bucketCreated = false;

/** @var list<string> $passed */
$passed = [];

$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) {
        throw new RuntimeException('Check failed: ' . $name);
    }
    $passed[] = $name;
    echo "PASS  {$name}\n";
};

try {
    $client->createBucket(['Bucket' => $bucket]);
    $bucketCreated = true;
    $check('CreateBucket', true);

    $put = $client->putObject(['Bucket' => $bucket, 'Key' => $smallKey, 'Body' => $smallBody]);
    $check('PutObject', trim((string) $put['ETag'], '"') === md5($smallBody));

    $get = $client->getObject(['Bucket' => $bucket, 'Key' => $smallKey]);
    $check('GetObject round trip', (string) $get['Body'] === $smallBody);

    $head = $client->headObject(['Bucket' => $bucket, 'Key' => $smallKey]);
    $check('HeadObject metadata', (int) $head['ContentLength'] === strlen($smallBody));

    $listed = $client->listObjectsV2(['Bucket' => $bucket]);
    $listedKeys = array_map(static fn (array $item): string => (string) $item['Key'], $listed['Contents'] ?? []);
    $check('ListObjectsV2', in_array($smallKey, $listedKeys, true));

    $client->copyObject([
        'Bucket' => $bucket,
        'Key' => $copyKey,
        'CopySource' => $bucket . '/' . $smallKey,
    ]);
    $copied = $client->getObject(['Bucket' => $bucket, 'Key' => $copyKey]);
    $check('CopyObject and read copy', (string) $copied['Body'] === $smallBody);

    $created = $client->createMultipartUpload(['Bucket' => $bucket, 'Key' => $multipartKey]);
    $multipartUploadId = (string) $created['UploadId'];
    $part = $client->uploadPart([
        'Bucket' => $bucket,
        'Key' => $multipartKey,
        'UploadId' => $multipartUploadId,
        'PartNumber' => 1,
        'Body' => $multipartBody,
    ]);
    $completed = $client->completeMultipartUpload([
        'Bucket' => $bucket,
        'Key' => $multipartKey,
        'UploadId' => $multipartUploadId,
        'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $part['ETag']]]],
    ]);
    $multipartUploadId = null;
    $multipartRead = $client->getObject(['Bucket' => $bucket, 'Key' => $multipartKey]);
    $check('Multipart upload and completion', hash('sha256', (string) $multipartRead['Body']) === hash('sha256', $multipartBody)
        && isset($completed['ETag']));

    $deleted = $client->deleteObjects([
        'Bucket' => $bucket,
        'Delete' => ['Objects' => [['Key' => $smallKey], ['Key' => $copyKey], ['Key' => $multipartKey]]],
    ]);
    $check('DeleteObjects batch', count($deleted['Deleted'] ?? []) === 3);

    $remaining = $client->listObjectsV2(['Bucket' => $bucket]);
    $check('Empty bucket after object cleanup', count($remaining['Contents'] ?? []) === 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL  ' . $error::class . ': ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($bucketCreated) {
        try {
            if ($multipartUploadId !== null) {
                $client->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $multipartKey, 'UploadId' => $multipartUploadId]);
            }
            $objects = $client->listObjectsV2(['Bucket' => $bucket]);
            $keys = array_map(static fn (array $item): array => ['Key' => (string) $item['Key']], $objects['Contents'] ?? []);
            if ($keys !== []) {
                $client->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => $keys, 'Quiet' => true]]);
            }
            $client->deleteBucket(['Bucket' => $bucket]);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'CLEANUP FAILED for temporary bucket ' . $bucket . ': ' . $cleanupError->getMessage() . "\n");
            $exitCode = 1;
        }
    }
}

if (($exitCode ?? 0) !== 0) {
    exit(1);
}

echo 'S3 smoke test passed (' . count($passed) . " checks); temporary bucket removed.\n";
