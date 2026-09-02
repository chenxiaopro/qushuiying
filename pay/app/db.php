<?php
/**
 * 数据库连接与查询封装 (PDO + MySQL)
 * 所有 SQL 一律使用预处理语句
 */

require_once __DIR__ . '/config.php';

class PDB
{
    private static $pdo = null;

    public static function pdo()
    {
        if (self::$pdo === null) {
            $c = pcfg('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
            $opt = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], $opt);
        }
        return self::$pdo;
    }

    public static function execute($sql, $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function one($sql, $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function all($sql, $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function scalar($sql, $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    public static function lastId()
    {
        return self::pdo()->lastInsertId();
    }
}
