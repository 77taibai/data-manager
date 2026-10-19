<?php
declare(strict_types=1);

namespace Dlm\Support;

use PDO;
use PDOStatement;
use Throwable;

final class Db
{
    private static ?PDO $pdo = null;

    /**
     * @param int|null $timeout 连接超时秒数，null 表示使用 PDO/php.ini 默认值（健康检查会显式传短超时）
     */
    public static function connect(array $db, ?int $timeout = null): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($db['host'] ?? '127.0.0.1'),
            (int) ($db['port'] ?? 3306),
            (string) ($db['name'] ?? ''),
            (string) ($db['charset'] ?? 'utf8mb4')
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($timeout !== null) {
            $options[PDO::ATTR_TIMEOUT] = $timeout;
        }
        $pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), $options);
        return $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect((array) Config::get('db', []));
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(static fn ($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $cols),
            implode(', ', $placeholders)
        );
        $params = [];
        foreach ($data as $k => $v) {
            $params[':' . $k] = $v;
        }
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $k => $v) {
            $sets[] = '`' . $k . '` = :set_' . $k;
            $params[':set_' . $k] = $v;
        }
        foreach ($whereParams as $k => $v) {
            $params[$k] = $v;
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return self::run($sql, $params)->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::run(sprintf('DELETE FROM `%s` WHERE %s', $table, $where), $params)->rowCount();
    }

    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function serverVersion(): string
    {
        return (string) self::pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }
}
