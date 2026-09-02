<?php
/**
 * 解析器工厂：根据分享链接识别平台，匹配后台配置的解析接口并分发
 *
 * 匹配优先级：
 *   1. 前端指定解析类型（mode 对应 parse_types.key，如 dyzy/dsp/douyinyh/live）
 *   2. 平台专属接口（apis.slug == 识别出的平台标识；取排序值最小的第一个）
 *   3. 通用远程接口（apis.slug == '*'）
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/parsers/ApiParser.php';
require_once __DIR__ . '/parsers/DouyinParser.php';
require_once __DIR__ . '/parsers/KuaishouParser.php';

class ParserFactory
{
    /** 识别平台标识，未识别返回 null */
    public static function detect($text)
    {
        $slug = detect_platform_slug($text);
        return $slug === '' ? null : $slug;
    }

    /**
     * 解析文本，返回标准结果数组
     * @param string $text 分享内容
     * @param string|null $mode 前端选择的解析类型（对应 parse_types.key），空=自动识别
     * @throws RuntimeException
     */
    public static function parse($text, $mode = null)
    {
        $text = trim((string)$text);
        if (mb_strlen($text) < 4) {
            throw new RuntimeException('分享内容过短');
        }

        $slug = detect_platform_slug($text);

        $mode = strtolower(trim((string)$mode));
        $api = null;
        if ($mode !== '' && $mode !== 'auto') {
            $api = self::findApiByMode($slug, $mode);
            if (!$api && $slug !== '' && $slug !== '*') {
                $api = self::findApiByMode('*', $mode);
            }
        }
        if (!$api) {
            $api = $slug !== '' ? self::findApi($slug) : null;
            if (!$api) {
                $api = self::findApi('*');
            }
        }

        if ($api) {
            return (new ApiParser($api))->parse($text);
        }

        if (DouyinParser::supports($text)) {
            return (new DouyinParser())->parse($text);
        }
        if (KuaishouParser::supports($text)) {
            return (new KuaishouParser())->parse($text);
        }

        throw new RuntimeException('暂不支持该平台，请在后台配置对应解析接口');
    }

    /** 查 apis 表：启用且 slug 匹配（优先排序值小的） */
    private static function findApi($slug)
    {
        return DB::one('SELECT * FROM apis WHERE enabled=1 AND slug=? ORDER BY sort ASC, id ASC LIMIT 1', [$slug]);
    }

    /**
     * 前端解析类型(parse_types.key) → 后台接口
     * 按接口配置的「解析类型」字段(apis.parse_type)匹配
     */
    private static function findApiByMode($slug, $mode)
    {
        $target = $slug !== '' ? $slug : '*';
        return DB::one(
            'SELECT * FROM apis WHERE enabled=1 AND slug=? AND parse_type=? ORDER BY sort ASC, id ASC LIMIT 1',
            [$target, $mode]
        );
    }
}
