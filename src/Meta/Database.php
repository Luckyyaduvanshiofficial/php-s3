<?php

declare(strict_types=1);

namespace MiniS3\Meta;

/**
 * Thin PDO wrapper: MySQL/MariaDB, exceptions on, reconnect-on-idle
 * (long uploads can outlive wait_timeout — lite-s3 lesson).
 */
final class Database
{
    private ?\PDO $pdo = null;
    private float $lastUsedAt = 0.0;

    /**
     * @param array{dsn: string, username: string, password: string}|null $config
     */
    public function __construct(
        private readonly ?array $config = null,
        private readonly int $reconnectAfterSeconds = 60,
    ) {
    }

    /** @param array{dsn: string, username: string, password: string} $config */
    public static function fromConfig(array $config): self
    {
        return new self($config);
    }

    public static function fromAppConfig(): self
    {
        $c = minis3_config();
        $db = $c['db'] ?? null;
        if (!is_array($db) || !isset($db['dsn'])) {
            throw new \RuntimeException('database is not configured (run the installer)');
        }

        return new self([
            'dsn' => (string) $db['dsn'],
            'username' => (string) ($db['username'] ?? ''),
            'password' => (string) ($db['password'] ?? ''),
        ]);
    }

    public function pdo(): \PDO
    {
        $now = microtime(true);
        if ($this->pdo !== null && ($now - $this->lastUsedAt) > $this->reconnectAfterSeconds) {
            try {
                $this->pdo->query('SELECT 1'); // ping
            } catch (\PDOException) {
                $this->pdo = null;
            }
        }

        if ($this->pdo === null) {
            if ($this->config === null) {
                throw new \RuntimeException('database is not configured');
            }
            $this->pdo = new \PDO($this->config['dsn'], $this->config['username'], $this->config['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        }
        $this->lastUsedAt = $now;

        return $this->pdo;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return \PDOStatement
     */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /** @param array<int|string, mixed> $params */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);

        return (int) $this->pdo()->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($this);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            // If the transaction is gone, DDL inside $fn implicitly committed
            // (MySQL/MariaDB auto-commits CREATE TABLE etc.) — nothing to do.

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
