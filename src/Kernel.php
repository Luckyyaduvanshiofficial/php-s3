<?php

declare(strict_types=1);

namespace PhpS3;

use PhpS3\Auth\Authenticator;
use PhpS3\Http\Request;
use PhpS3\Http\Response;
use PhpS3\Meta\AccessKeyRepository;
use PhpS3\Meta\BucketRepository;
use PhpS3\Meta\Crypto;
use PhpS3\Meta\Database;
use PhpS3\Meta\MultipartRepository;
use PhpS3\Meta\ObjectRepository;
use PhpS3\Meta\UserRepository;
use PhpS3\S3\Exception\S3Exception;
use PhpS3\S3\Handlers\BucketHandler;
use PhpS3\S3\Handlers\ListHandler;
use PhpS3\S3\Handlers\MultipartHandler;
use PhpS3\S3\Handlers\ObjectHandler;
use PhpS3\S3\Handlers\ObjectResponder;
use PhpS3\S3\Handlers\ServiceHandler;
use PhpS3\S3\OperationResolver;
use PhpS3\S3\S3Operation;
use PhpS3\Storage\LocalFilesystemStorage;
use PhpS3\Storage\StorageInterface;

/**
 * Request router + dispatcher. Single catch boundary converts S3Exception
 * to AWS-accurate status + XML; anything else becomes InternalError.
 */
final class Kernel
{
    private readonly array $config;

    /** @var \stdClass|null lazy service container */
    private ?\stdClass $services = null;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? php_s3_config();
    }

    public function handle(Request $request): Response
    {
        $_SERVER['PHPS3_REQUEST_ID'] = $request->requestId;

        try {
            return $this->dispatch($request);
        } catch (S3Exception $e) {
            return Response::xml($e->status, $e->errorCode, $e->awsMessage, $request->requestId, $e->extraXml)
                ->withHeaders($e->extraHeaders + ['x-amz-request-id' => $request->requestId]);
        }
    }

    private function dispatch(Request $request): Response
    {
        $path = $request->path;

        /* -------------------------------------------------- health check */
        if ($path === '/_health' || str_starts_with($path, '/_health')) {
            return Response::text(200, "php-s3 ok\n", ['Cache-Control' => 'no-store']);
        }

        /* ------------------------------------------------------ installer */
        if (!php_s3_installed()) {
            if (str_starts_with($path, '/_admin/install')) {
                return $this->admin()->install($request);
            }
            if (str_starts_with($path, '/_admin')) {
                return Response::redirect(302, '/_admin/install');
            }
            // S3 traffic before installation: honest 503, not a HTML redirect.
            throw new S3Exception('ServiceUnavailable', 'php-s3 is not installed yet. Open /_admin/install to finish setup.', 503);
        }

        /* --------------------------------------------------------- admin */
        if (str_starts_with($path, '/_admin')) {
            return $this->admin()->handle($request);
        }

        /* ----------------------------------------------------------- S3 */
        $parsed = OperationResolver::parsePath($request, (string) ($this->config['base_domain'] ?? ''));
        if ($parsed['is_admin']) {
            return Response::text(404, 'Not Found');
        }

        $operation = OperationResolver::resolve($request, $parsed);

        $auth = $this->authenticator()->authenticate($request);

        // Size gate before any bucket/DB work (cheap DoS guard).
        $maxBytes = $this->maxObjectBytes();
        $declared = $request->contentLength();
        if ($declared !== null && $declared > $maxBytes && in_array($operation, [S3Operation::ObjectPut, S3Operation::ObjectCopy], true)) {
            throw S3Exception::entityTooLarge($maxBytes);
        }

        if ($parsed['bucket'] !== null && $auth->allowedBuckets !== null
            && !in_array($parsed['bucket'], $auth->allowedBuckets, true)
        ) {
            // Existence is not revealed to unauthorized keys (anti-enumeration).
            throw S3Exception::accessDenied('/' . $parsed['bucket']);
        }

        return $this->execute($request, $auth, $parsed, $operation);
    }

    /**
     * @param array{scope: string, bucket: ?string, key: ?string, is_admin: bool} $parsed
     */
    private function execute(Request $request, \PhpS3\Auth\AuthContext $auth, array $parsed, S3Operation $op): Response
    {
        $bucket = $parsed['bucket'] ?? '';
        $key = $parsed['key'];

        switch ($op) {
            case S3Operation::ServiceListBuckets:
                return $this->services()->service->listBuckets($request, $auth);

            case S3Operation::BucketCreate:
            case S3Operation::BucketHead:
            case S3Operation::BucketDelete:
                return $this->services()->bucket->handle($request, $auth, $bucket, $op);

            case S3Operation::ObjectPut:
            case S3Operation::ObjectCopy:
            case S3Operation::ObjectGet:
            case S3Operation::ObjectHead:
            case S3Operation::ObjectDelete:
            case S3Operation::ObjectsDelete:
                return $this->services()->object->handle($request, $auth, $bucket, $key, $op);

            case S3Operation::ListObjectsV1:
                return $this->services()->list->list($auth, $bucket, $request->query(), false);

            case S3Operation::ListObjectsV2:
                return $this->services()->list->list($auth, $bucket, $request->query(), true);

            case S3Operation::MultipartCreate:
            case S3Operation::MultipartUploadPart:
            case S3Operation::MultipartComplete:
            case S3Operation::MultipartAbort:
            case S3Operation::MultipartListParts:
            case S3Operation::MultipartListUploads:
                return $this->services()->multipart->handle($request, $auth, $bucket, $key, $op);

            default:
                // Unreachable in practice: ObjectGetPresigned never resolves
                // (presigned requests resolve their underlying operation and
                // are verified in Authenticator).
                throw S3Exception::notImplemented(
                    "Operation '{$op->value}' is not available.",
                );
        }
    }

    /* ------------------------------------------------------- services */

    private function authenticator(): Authenticator
    {
        return new Authenticator($this->accessKeys(), (string) ($this->config['region'] ?? 'us-east-1'));
    }

    private function admin(): \PhpS3\Admin\AdminKernel
    {
        if (!php_s3_installed()) {
            // Pre-install: only the installer route runs, and it bootstraps
            // the DB itself — there is no config to build repositories from.
            return new \PhpS3\Admin\AdminKernel();
        }

        return new \PhpS3\Admin\AdminKernel($this->db(), $this->users(), $this->accessKeys(), $this->buckets(), $this->objects(), $this->storage());
    }

    private function services(): object
    {
        if ($this->services === null) {
            $storage = $this->storage();
            $service = new ServiceHandler($this->buckets(), $this->users(), $storage);
            $bucket = new BucketHandler($this->buckets(), $this->objects(), $storage, (int) ($this->config['max_buckets'] ?? 100));
            $responder = new ObjectResponder($storage);
            $object = new ObjectHandler($this->buckets(), $this->objects(), $storage, $responder, $this->maxObjectBytes());
            $list = new ListHandler($this->buckets(), $this->objects());
            $multipart = new MultipartHandler($this->buckets(), $this->objects(), $this->multipartUploads(), $storage, $this->maxObjectBytes());

            $this->services = (object) compact('service', 'bucket', 'object', 'list', 'multipart');
        }

        return $this->services;
    }

    private function db(): Database
    {
        static $db;
        return $db ??= Database::fromAppConfig();
    }

    private function storage(): StorageInterface
    {
        static $storage;
        if ($storage === null) {
            $root = (string) ($this->config['data_root'] ?? '');
            if ($root === '') {
                throw new \RuntimeException('config data_root is missing');
            }
            $storage = new LocalFilesystemStorage($root);
        }

        return $storage;
    }

    private function accessKeys(): AccessKeyRepository
    {
        static $repo;
        return $repo ??= new AccessKeyRepository($this->db(), Crypto::fromAppConfig());
    }

    private function buckets(): BucketRepository
    {
        static $repo;
        return $repo ??= new BucketRepository($this->db());
    }

    private function objects(): ObjectRepository
    {
        static $repo;
        return $repo ??= new ObjectRepository($this->db());
    }

    private function multipartUploads(): MultipartRepository
    {
        static $repo;
        return $repo ??= new MultipartRepository($this->db());
    }

    private function users(): UserRepository
    {
        static $repo;
        return $repo ??= new UserRepository($this->db());
    }

    private function maxObjectBytes(): int
    {
        $value = $this->config['max_object_bytes'] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return 5 * 1024 * 1024 * 1024; // 5 GiB (multipart is the practical ceiling anyway)
    }
}
