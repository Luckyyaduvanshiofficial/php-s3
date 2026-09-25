<?php

declare(strict_types=1);

namespace PhpS3\S3\Exception;

/**
 * Every error the S3 client sees is one of these. The single catch site in
 * Kernel converts it to the AWS-accurate status + XML body.
 */
class S3Exception extends \RuntimeException
{
    /** @param array<string, string> $extraXml tag => value for the <Error> document */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $awsMessage,
        public readonly int $status = 400,
        public readonly array $extraXml = [],
        public readonly array $extraHeaders = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode . ': ' . $awsMessage, 0, $previous);
    }

    /* ------------------------------------------------------- factories */

    public static function noSuchBucket(string $bucket): self
    {
        return new self('NoSuchBucket', "The specified bucket does not exist: {$bucket}", 404, ['BucketName' => $bucket]);
    }

    public static function noSuchKey(string $key): self
    {
        return new self('NoSuchKey', "The specified key does not exist: {$key}", 404, ['Key' => $key]);
    }

    public static function bucketAlreadyOwnedByYou(string $bucket): self
    {
        return new self('BucketAlreadyOwnedByYou', "Your previous request to create the named bucket succeeded and it already exists.", 409, ['BucketName' => $bucket]);
    }

    public static function bucketNotEmpty(string $bucket): self
    {
        return new self('BucketNotEmpty', 'The bucket you tried to delete is not empty.', 409, ['BucketName' => $bucket]);
    }

    public static function accessDenied(string $resource = ''): self
    {
        $extra = $resource === '' ? [] : ['Resource' => $resource];

        return new self('AccessDenied', 'Access Denied.', 403, $extra);
    }

    public static function invalidAccessKeyId(): self
    {
        return new self('InvalidAccessKeyId', 'The AWS Access Key Id you provided does not exist in our records.', 403);
    }

    public static function signatureDoesNotMatch(): self
    {
        return new self('SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided. Check your key and signing method.', 403);
    }

    public static function authorizationHeaderMalformed(string $message = 'The authorization header is malformed.'): self
    {
        return new self('AuthorizationHeaderMalformed', $message, 400);
    }

    public static function requestTimeTooSkewed(): self
    {
        return new self('RequestTimeTooSkewed', "The difference between the request time and the current time is too large.", 400);
    }

    public static function missingSecurityHeader(string $header): self
    {
        return new self('MissingSecurityHeader', "Your request is missing a required header: {$header}", 400, ['HeaderName' => $header]);
    }

    public static function invalidArgument(string $name, string $value, string $message): self
    {
        return new self('InvalidArgument', $message, 400, ['ArgumentName' => $name, 'ArgumentValue' => $value]);
    }

    public static function invalidBucketName(string $bucket): self
    {
        return new self('InvalidBucketName', 'The specified bucket is not valid.', 400, ['BucketName' => $bucket]);
    }

    public static function invalidRequest(string $message): self
    {
        return new self('InvalidRequest', $message, 400);
    }

    public static function entityTooLarge(int $maxBytes): self
    {
        return new self('EntityTooLarge', 'Your proposed upload exceeds the maximum allowed object size.', 411, ['MaxSizeAllowed' => (string) $maxBytes]);
    }

    public static function incompleteBody(string $expected, string $actual): self
    {
        return new self('IncompleteBody', 'You did not provide the number of bytes specified by the Content-Length HTTP header.', 400, [
            'ExpectedContentLength' => $expected,
            'ProxiedContentLength' => $actual,
        ]);
    }

    public static function badDigest(string $message = 'The Content-MD5 you specified did not match what we received.'): self
    {
        return new self('BadDigest', $message, 400);
    }

    public static function xAmzContentSha256Mismatch(): self
    {
        return new self('XAmzContentSHA256Mismatch', "The provided 'x-amz-content-sha256' header does not match what was computed.", 400);
    }

    public static function preconditionFailed(string $header): self
    {
        return new self('PreconditionFailed', "At least one of the pre-conditions you specified did not hold: {$header}", 412, ['Condition' => $header]);
    }

    public static function invalidRange(int $size): self
    {
        return new self('InvalidRange', "The requested range is not satisfiable.", 416, ['ActualObjectSize' => (string) $size]);
    }

    public static function slowDown(): self
    {
        return new self('SlowDown', 'Please reduce your request rate.', 503, [], ['Retry-After' => '1']);
    }

    public static function notImplemented(string $message = 'Not implemented yet.'): self
    {
        return new self('NotImplemented', $message, 501);
    }

    public static function malformedXml(string $message = 'The XML you provided was not well-formed or did not validate against our published schema.'): self
    {
        return new self('MalformedXML', $message, 400);
    }

    public static function noSuchUpload(): self
    {
        return new self('NoSuchUpload', 'The specified multipart upload does not exist. The upload ID may be invalid, or the upload may have been aborted or completed.', 404);
    }

    public static function invalidPart(string $partNumber = ''): self
    {
        return new self('InvalidPart', "One or more of the specified parts could not be found. The part may not have been uploaded, or the specified entity tag may not have matched the part's entity tag.", 400, $partNumber === '' ? [] : ['PartNumber' => $partNumber]);
    }

    public static function invalidPartOrder(): self
    {
        return new self('InvalidPartOrder', 'The list of parts was not in ascending order. Parts must be ordered by part number.', 400);
    }

    public static function entityTooSmall(): self
    {
        return new self('EntityTooSmall', 'Your proposed upload is smaller than the minimum allowed size.', 400);
    }

    public static function requestExpired(): self
    {
        return new self('AccessDenied', 'Request has expired.', 403);
    }

    public static function authorizationQueryParametersError(string $message): self
    {
        return new self('AuthorizationQueryParametersError', $message, 400);
    }

    public static function internalError(string $message = 'We encountered an internal error. Please try again.'): self
    {
        return new self('InternalError', $message, 500);
    }

    public static function methodNotAllowed(): self
    {
        return new self('MethodNotAllowed', 'The specified method is not allowed against this resource.', 405);
    }

    public static function keyTooLongError(int $max = 1024): self
    {
        return new self('KeyTooLongError', "Your key is too long; maximum allowed length is {$max} bytes.", 400);
    }

    public static function tooManyBuckets(): self
    {
        return new self('TooManyBuckets', 'You have attempted to create more buckets than allowed.', 400);
    }
}
