<?php
/**
 * 快手解析器
 * 原理：
 *   1. 提取分享链接，跟踪 v.kuaishou.com 短链
 *   2. 从重定向地址提取 photoId
 *   3. 请求视频详情页，从内嵌 JSON/HTML 中提取无水印地址
 * 注意：平台接口会不定期调整，如失效请在 app/parsers/KuaishouParser.php 中更新
 */

require_once __DIR__ . '/BaseParser.php';

class KuaishouParser extends BaseParser
{
    public static function key()
    {
        return 'kuaishou';
    }

    public static function supports($text)
    {
        return (bool)preg_match('#kuaishou\.com|gifshow\.com#i', (string)$text);
    }

    public function parse($text)
    {
        $url = $this->pickUrl($text);

        if (preg_match('#v\.kuaishou\.com#i', $url)) {
            $url = get_final_url($url);
        }

        // photoId 提取
        if (!preg_match('#(?:short-video|fw/photo)/([0-9A-Za-z_\-]+)#i', $url, $m)) {
            $this->apiError('未从链接中提取到作品ID');
        }
        $photoId = $m[1];

        // 方式一：详情页 HTML 内提取
        $html = http_get('https://www.kuaishou.com/short-video/' . $photoId, [
            'Referer: https://www.kuaishou.com/',
        ], 12);

        $result = $this->parseFromHtml($html);
        if (!$result) {
            $this->apiError('详情页未解析到视频数据');
        }
        return $result;
    }

    /** 从页面 JSON 数据中解析，字段名以实际返回为准 */
    private function parseFromHtml($html)
    {
        $videoUrl = '';
        $cover = '';
        $title = '';

        if (preg_match('#"srcNoMark"\s*:\s*"([^"]+)"#i', $html, $m)) {
            $videoUrl = $m[1];
        } elseif (preg_match('#"src"\s*:\s*"([^"]+\.mp4[^"]*)"#i', $html, $m)) {
            $videoUrl = $m[1];
        } elseif (preg_match('#<video[^>]+src="([^"]+)"#i', $html, $m)) {
            $videoUrl = $m[1];
        }

        if (preg_match('#"coverUrl"\s*:\s*"([^"]+)"#i', $html, $m)) {
            $cover = $m[1];
        } elseif (preg_match('#"poster"\s*:\s*"([^"]+)"#i', $html, $m)) {
            $cover = $m[1];
        }

        if (preg_match('#<title>(.*?)</title>#is', $html, $m)) {
            $title = trim(strip_tags($m[1]));
        }

        $videoUrl = str_replace('\\u002F', '/', $videoUrl);
        $cover = str_replace('\\u002F', '/', $cover);

        if (!$videoUrl) {
            return null;
        }
        return [
            'platform'  => self::key(),
            'title'     => mb_substr($title, 0, 200),
            'cover'     => $cover,
            'video_url' => $videoUrl,
            'music'     => '',
        ];
    }
}
