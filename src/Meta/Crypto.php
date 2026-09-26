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

namespace PhpS3\Meta;

/**
 * AES-256-GCM encryption for S3 secrets at rest.
 * The master key lives in config (outside the web root) — a database dump
 * alone is useless. SigV4 requires the plaintext secret back, so hashing
 * (bcrypt) is impossible by design.
 */
final class Crypto
{
    private string $key;

    /** @param string $masterKey hex-encoded 32 bytes (64 hex chars) */
    public function __construct(string $masterKey)
    {
        $raw = @hex2bin($masterKey);
        if ($raw === false || strlen($raw) !== 32) {
            throw new \RuntimeException('config secret_key must be 64 hex characters (32 bytes)');
        }
        $this->key = $raw;
    }

    public static function fromAppConfig(): self
    {
        $c = php_s3_config();
        $key = $c['secret_key'] ?? '';
        if (!is_string($key) || $key === '') {
            throw new \RuntimeException('config secret_key is missing');
        }

        return new self($key);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('encryption failed');
        }

        return $iv . $tag . $ct;
    }

    public function decrypt(string $blob): string
    {
        if (strlen($blob) < 29) {
            throw new \RuntimeException('encrypted blob too short');
        }
        $iv = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $ct = substr($blob, 28);
        $pt = openssl_decrypt($ct, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($pt === false) {
            throw new \RuntimeException('decryption failed (secret_key changed?)');
        }

        return $pt;
    }

    public static function generateMasterKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function generateAccessKeyId(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $id = 'AKIA';
        for ($i = 0; $i < 16; $i++) {
            $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $id;
    }

    public static function generateSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    }
}
