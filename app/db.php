<?php
/**
 * 数据库连接与查询封装 (PDO + MySQL)
 * 所有 SQL 一律使用预处理语句，防止注入
 */

require_once __DIR__ . '/config.php';

class DB
{
    /** @var PDO|null */
    private static $pdo = null;

    public static function pdo()
    {
        if (self::$pdo === null) {
            $c = cfg('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
            $opt = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], $opt);
            self::ensureSchema();
        }
        return self::$pdo;
    }

    /** 覆盖部署后的自动迁移：补齐 apis.parse_type 字段与 parse_types 表及默认种子 */
    private static function ensureSchema()
    {
        try {
            $cols = self::$pdo->query("SHOW COLUMNS FROM `apis` LIKE 'parse_type'")->fetchAll();
            if (!$cols) {
                self::$pdo->exec("ALTER TABLE `apis` ADD COLUMN `parse_type` VARCHAR(20) NULL DEFAULT '' COMMENT '解析类型'");
            }
        } catch (Throwable $e) {
            // 忽略
        }
        try {
            $tables = self::$pdo->query("SHOW TABLES LIKE 'parse_types'")->fetchAll();
            if (!$tables) {
                self::$pdo->exec("CREATE TABLE `parse_types` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name` VARCHAR(32) NOT NULL COMMENT '显示名称',
                    `key` VARCHAR(32) NOT NULL COMMENT '标识',
                    `sort` INT NOT NULL DEFAULT 0 COMMENT '排序(越小越靠前)',
                    `enabled` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_key` (`key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='解析类型表'");
                self::$pdo->exec("INSERT INTO `parse_types` (`name`,`key`,`sort`,`enabled`) VALUES
                    ('抖音主页解析','dyzy',1,1),
                    ('短视频/图集解析','dsp',2,1),
                    ('抖音原画质','douyinyh',3,1),
                    ('实况解析','live',4,1)");
            }
        } catch (Throwable $e) {
            // 忽略
        }
        try {
            $tables = self::$pdo->query("SHOW TABLES LIKE 'versions'")->fetchAll();
            if (!$tables) {
                self::$pdo->exec("CREATE TABLE `versions` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `version` VARCHAR(20) NOT NULL COMMENT '版本号',
                    `title` VARCHAR(100) NOT NULL COMMENT '更新标题',
                    `type` VARCHAR(20) NOT NULL DEFAULT 'update' COMMENT '类型:update更新/optimize优化/fix修复',
                    `content` TEXT NULL COMMENT '更新内容',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='版本更新记录'");
            }
        } catch (Throwable $e) {
            // 忽略
        }
        try {
            self::$pdo->exec("INSERT IGNORE INTO `settings` (`k`,`v`) VALUES
                ('wechat_enabled','0'),('wechat_name',''),('wechat_qrcode',''),('wechat_desc',''),('site_version','v1.2.0'),
                ('epay_public_key',''),('epay_private_key',''),
                ('alipay_enabled','0'),('alipay_app_id',''),('alipay_private_key',''),('alipay_public_key',''),
                ('bark_enabled','0'),('bark_server','https://api.day.app'),('bark_key',''),('bark_notify_register','0'),('bark_notify_recharge','0')");
            self::$pdo->exec("UPDATE `settings` SET `v`='v1.2.0' WHERE `k`='site_version' AND `v`='v1.1.0'");
        } catch (Throwable $e) {
            // 忽略
        }
        try {
            $idx = self::$pdo->query("SHOW INDEX FROM `orders` WHERE Key_name='uk_sn'")->fetchAll();
            if (!$idx) {
                self::$pdo->exec('ALTER TABLE `orders` ADD UNIQUE KEY `uk_sn` (`order_sn`)');
            }
        } catch (Throwable $e) {
            // 忽略
        }
    }

    /** 执行并返回受影响行数 */
    public static function execute($sql, $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** 查询一行 */
    public static function one($sql, $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** 查询多行 */
    public static function all($sql, $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** 取单列单值 */
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

/** 读取全部站点设置（带进程内缓存） */
function settings_all()
{
    if (!array_key_exists('WM_SETTINGS', $GLOBALS) || !is_array($GLOBALS['WM_SETTINGS'])) {
        $GLOBALS['WM_SETTINGS'] = [];
        try {
            foreach (DB::all('SELECT k,v FROM settings') as $r) {
                $GLOBALS['WM_SETTINGS'][$r['k']] = $r['v'];
            }
        } catch (Exception $e) {
            $GLOBALS['WM_SETTINGS'] = [];
        }
    }
    return $GLOBALS['WM_SETTINGS'];
}

/** 读取站点设置 */
function setting($key, $default = '')
{
    $all = settings_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

/** 保存站点设置（同步更新进程内缓存，保证同请求内读写一致） */
function set_setting($key, $value)
{
    DB::execute('INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$key, (string)$value]);
    if (!array_key_exists('WM_SETTINGS', $GLOBALS) || !is_array($GLOBALS['WM_SETTINGS'])) {
        settings_all();
    }
    $GLOBALS['WM_SETTINGS'][$key] = (string)$value;
}
