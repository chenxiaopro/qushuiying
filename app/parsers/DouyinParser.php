<?php
/**
 * 抖音解析器
 * 原理：
 *   1. 从分享文本提取链接，跟踪 v.douyin.com 短链得到真实地址
 *   2. 从地址中提取 aweme_id（视频 /video/{id} 或图文 /note/{id}）
 *   3. 调用抖音 web 接口获取无水印地址
 * 注意：平台接口会不定期调整，如失效请在 app/parsers/DouyinParser.php 中更新
 */

require_once __DIR__ . '/BaseParser.php';

class DouyinParser extends BaseParser
{
    public static function key()
    {
        return 'douyin';
    }

    public static function supports($text)
    {
        return (bool)preg_match('#douyin\.com|iesdouyin\.com#i', (string)$text);
    }

    public function parse($text)
    {
        $url = $this->pickUrl($text);

        // 短链重定向，拿到真实页面地址
        if (preg_match('#v\.douyin\.com#i', $url)) {
            $url = get_final_url($url);
        }

        // 提取 id：/video/{id}  /note/{id}  /share/video/{id}
        if (!preg_match('#(?:/video|/note|/share/video)/([0-9]+)#i', $url, $m)) {
            $this->apiError('未从链接中提取到视频ID');
        }
        $awemeId = $m[1];

        // 接口一：web 详情接口（可能需 cookie，尽力尝试）
        $data = $this->tryApi('https://www.iesdouyin.com/web/api/v2/aweme/iteminfo/?item_ids=' . $awemeId);

        $detail = null;
        if (!empty($data['item_list'][0])) {
            $detail = $data['item_list'][0];
        } elseif (!empty($data['aweme_detail'])) {
            $detail = $data['aweme_detail'];
        }

        if (!$detail) {
            $this->apiError('接口未返回视频数据');
        }

        $videoUrl = '';
        $cover = '';
        if (!empty($detail['video']['play_addr']['url_list'])) {
            $videoUrl = $this->cleanUrl($detail['video']['play_addr']['url_list'][0]);
        } elseif (!empty($detail['video']['play_addr']['url'])) {
            $videoUrl = $this->cleanUrl($detail['video']['play_addr']['url']);
        }
        if (!empty($detail['video']['cover']['url_list'])) {
            $cover = $detail['video']['cover']['url_list'][0];
        }

        if (!$videoUrl) {
            $this->apiError('未获取到无水印视频地址');
        }

        return [
            'platform'  => self::key(),
            'title'     => mb_substr($detail['desc'] ?? '', 0, 200),
            'cover'     => $cover,
            'video_url' => $videoUrl,
            'music'     => '',
        ];
    }

    private function tryApi($url)
    {
        $headers = [
            'Referer: https://www.douyin.com/',
            'Accept: application/json, text/plain, */*',
        ];
        $resp = http_get($url, $headers, 12);
        $json = json_decode($resp, true);
        if (!is_array($json)) {
            $this->apiError('接口返回非JSON');
        }
        return $json;
    }
}
