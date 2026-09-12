<?php
/**
 * IP 地理定位（基于 ip-api.com 免费接口）
 *
 * 负责把公网 IP 解析为中国省级行政区，并将结果缓存到 ip_geo 表。
 * 免费接口单次批量上限 100 个 IP，调用失败时静默降级为「未知地区」。
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

class GeoIp
{
    /** 单次最多解析的未缓存 IP 数量（ip-api 免费版批量上限） */
    const BATCH_LIMIT = 100;

    /** 本次解析过程中外部服务是否可用 */
    private static $serviceOk = true;

    /** 缓存表是否已确保存在 */
    private static $tableReady = false;

    /** 中国省级行政区短名白名单 */
    private static $chinaProvinces = [
        '北京', '天津', '河北', '山西', '内蒙古', '辽宁', '吉林', '黑龙江',
        '上海', '江苏', '浙江', '安徽', '福建', '江西', '山东', '河南',
        '湖北', '湖南', '广东', '广西', '海南', '重庆', '四川', '贵州',
        '云南', '西藏', '陕西', '甘肃', '青海', '宁夏', '新疆',
        '台湾', '香港', '澳门',
    ];

    /** 全称到短名的别名映射 */
    private static $provinceAlias = [
        '内蒙古自治区'     => '内蒙古',
        '广西壮族自治区'   => '广西',
        '西藏自治区'       => '西藏',
        '宁夏回族自治区'   => '宁夏',
        '新疆维吾尔自治区' => '新疆',
        '香港特别行政区'   => '香港',
        '澳门特别行政区'   => '澳门',
    ];

    /** 本次定位外部服务是否可用 */
    public static function serviceOk()
    {
        return self::$serviceOk;
    }

    /** 确保缓存表存在（老库免迁移，CREATE IF NOT EXISTS 幂等） */
    public static function ensureTable()
    {
        if (self::$tableReady) {
            return;
        }
        self::$tableReady = true;
        try {
            DB::execute(
                "CREATE TABLE IF NOT EXISTS `ip_geo` (
                    `ip` VARCHAR(45) NOT NULL,
                    `country` VARCHAR(64) NULL,
                    `province` VARCHAR(64) NULL,
                    `city` VARCHAR(64) NULL,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`ip`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IP 地理定位缓存'"
            );
        } catch (Throwable $e) {
            error_log('[wm-geo] ensureTable: ' . $e->getMessage());
        }
    }

    /** 是否属于中国省级行政区白名单 */
    public static function isChinaProvince($short)
    {
        return in_array(trim((string)$short), self::$chinaProvinces, true);
    }

    /** 省份全称归一化为短名（广东省 -> 广东，内蒙古自治区 -> 内蒙古） */
    public static function normalizeProvince($name)
    {
        $name = trim((string)$name);
        if ($name === '') {
            return '';
        }
        if (isset(self::$provinceAlias[$name])) {
            return self::$provinceAlias[$name];
        }
        $short = preg_replace('/(省|市)$/u', '', $name);
        if ($short === null) {
            return $name;
        }
        return isset(self::$provinceAlias[$short]) ? self::$provinceAlias[$short] : $short;
    }

    /**
     * 批量定位公网 IP，返回 ip => [country, province, city] 映射。
     * 已缓存直接复用；未缓存的调用外部服务（单次不超过 BATCH_LIMIT 个）。
     */
    public static function locateBatch(array $ips)
    {
        self::ensureTable();
        $ips = array_values(array_unique(array_filter(array_map('trim', $ips), function ($ip) {
            return $ip !== ''
                && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        })));
        if (empty($ips)) {
            return [];
        }

        $result = [];
        foreach (array_chunk($ips, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $rows = DB::all("SELECT ip, country, province, city FROM ip_geo WHERE ip IN ($ph)", $chunk);
            foreach ($rows as $r) {
                $result[$r['ip']] = [
                    'country'  => (string)($r['country'] ?? ''),
                    'province' => (string)($r['province'] ?? ''),
                    'city'     => (string)($r['city'] ?? ''),
                ];
            }
        }

        $uncached = [];
        foreach ($ips as $ip) {
            if (!isset($result[$ip])) {
                $uncached[] = $ip;
            }
        }
        if ($uncached) {
            self::fetchRemote(array_slice($uncached, 0, self::BATCH_LIMIT), $result);
        }
        return $result;
    }

    /** 调用 ip-api.com 批量接口并写缓存；失败时标记 serviceOk=false */
    private static function fetchRemote(array $ips, array &$result)
    {
        $url = 'http://ip-api.com/batch?lang=zh-CN&fields=status,country,regionName,city,query';
        try {
            $resp = http_post($url, array_values($ips), [], 12, true);
        } catch (Throwable $e) {
            error_log('[wm-geo] ' . $e->getMessage());
            self::$serviceOk = false;
            return;
        }
        $json = json_decode((string)$resp, true);
        if (!is_array($json)) {
            self::$serviceOk = false;
            return;
        }
        foreach ($json as $item) {
            if (!is_array($item)) {
                continue;
            }
            $ip = trim((string)($item['query'] ?? ''));
            if ($ip === '') {
                continue;
            }
            $ok = ($item['status'] ?? '') === 'success';
            $country = $ok ? (string)($item['country'] ?? '') : '';
            $province = $ok ? self::normalizeProvince((string)($item['regionName'] ?? '')) : '';
            $city = $ok ? (string)($item['city'] ?? '') : '';
            $result[$ip] = ['country' => $country, 'province' => $province, 'city' => $city];
            self::saveCache($ip, $country, $province, $city);
        }
    }

    /** 写入缓存，空省份也保存以避免反复请求 */
    private static function saveCache($ip, $country, $province, $city)
    {
        try {
            DB::execute(
                'INSERT INTO ip_geo(ip,country,province,city,updated_at) VALUES(?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE country=VALUES(country),province=VALUES(province),city=VALUES(city),updated_at=NOW()',
                [$ip, $country, $province, $city]
            );
        } catch (Throwable $e) {
            error_log('[wm-geo] cache: ' . $e->getMessage());
        }
    }
}
