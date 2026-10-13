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
 */

namespace PhpS3\Meta;

/** Small database-backed store for administrator-managed platform settings. */
final class SettingsRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key): ?string
    {
        $row = $this->db->one('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
        return $row === null ? null : (string) $row['setting_value'];
    }

    public function set(string $key, string $value): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->run(
            'INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)',
            [$key, $value, $now],
        );
    }

    public function storageCapacityBytes(): ?int
    {
        $value = $this->get('storage_capacity_bytes');
        if ($value === null || !preg_match('/^\d+$/', $value)) {
            return null;
        }
        return (int) $value;
    }

    public function delete(string $key): void
    {
        $this->db->run('DELETE FROM system_settings WHERE setting_key = ?', [$key]);
    }
}
