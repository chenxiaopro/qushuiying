<?php
/**
 * 远程解析接口解析器（多接口）
 *
 * 根据 apis 表中的接口配置（api_url + 字段映射）调用第三方聚合解析接口，
 * 提取无水印地址。字段映射说明：
 *   resp_data  接口返回 JSON 中承载结果的数组字段名（空=根级）
 *   resp_title / resp_video / resp_img  该数组内的标题/视频/图片字段名
 *
 * 兼容常见返回格式：
 *   { "code":"0001", "title":"...", "playAddr":"无水印视频", "cover":"...",
 *     "pics":[...], "music":{...}, "video_backup":[...], "type":1 }
 */

require_once __DIR__ . '/BaseParser.php';

class ApiParser extends BaseParser
{
    /** @var array apis 表接口配置行 */
    private $api;

    public function __construct($api)
    {
        $this->api = $api;
    }

    public static function key()
    {
        return 'api';
    }

    public static function supports($text)
    {
        return true;
    }

    public function parse($text)
    {
        $url = $this->pickUrl($text);

        $apiUrl = trim((string)($this->api['api_url'] ?? ''));
        if ($apiUrl === '') {
            throw new RuntimeException('该接口未配置远程地址');
        }

        $fullUrl = self::buildRequestUrl($apiUrl, $url);
        $host = parse_url($fullUrl, PHP_URL_HOST);
        if (!$host || is_private_host($host)) {
            throw new RuntimeException('接口地址不安全，禁止访问内网地址');
        }

        $resp = http_get($fullUrl, ['Referer: ' . $apiUrl], 25);
        $json = json_decode($resp, true);
        if (!is_array($json)) {
            $this->apiError('接口返回非 JSON');
        }

        // 返回数组字段：空则取根级
        $respData = trim((string)($this->api['resp_data'] ?? ''));
        if ($respData !== '' && isset($json[$respData]) && is_array($json[$respData])) {
            // 列表返回（数字索引数组，元素为对象）→ 主页/多作品解析
            if (isset($json[$respData][0]) && is_array($json[$respData][0])) {
                return $this->parseList($json[$respData]);
            }
            $data = $json[$respData];
        } else {
            $data = $json;
        }

        $title = self::field($data, $this->api['resp_title'], 'title');
        $video = self::field($data, $this->api['resp_video'], 'playAddr');
        $cover = self::field($data, $this->api['resp_img'], 'cover');

        // 兼容字段（无需映射，按约定名尽力提取）
        $desc   = self::field($data, '', 'desc');
        $type   = (int)self::field($data, '', 'type');
        if ($type <= 0) {
            $type = 1;
        }
        $music  = $data['music'] ?? null;
        if (!is_array($music)) {
            $music = [];
        }
        $pics = self::extractUrls($data['pics'] ?? $data['images'] ?? null);
        $backup = $data['video_backup'] ?? [];
        if (!is_array($backup)) {
            $backup = [];
        }
        $live = self::extractUrls(
            $data['liveAddr'] ?? $data['live'] ?? $data['live_addr'] ?? $data['liveUrl'] ?? $data['live_urls'] ?? null
        );
        $liveFromPics = self::extractUrlsFromItems(
            $data['pics'] ?? $data['images'] ?? null,
            ['live', 'liveAddr', 'live_url', 'liveUrl']
        );
        if (!empty($liveFromPics)) {
            $live = array_values(array_unique(array_merge($live, $liveFromPics)));
        }

        if ($type === 3 || (count($live) > 1) || (count($live) > 0 && count($pics) > 1)) {
            $type = 3;
            if ($cover === '' && !empty($pics)) {
                $cover = $pics[0];
            }
            $video = '';
        }

        $result = [
            'platform'     => $data['platform'] ?? $this->api['name'] ?? '自定义接口',
            'title'        => $title,
            'desc'         => $desc,
            'cover'        => $cover,
            'video_url'    => $video,
            'music'        => [
                'title'  => (string)($music['title'] ?? ''),
                'author' => (string)($music['author'] ?? ''),
                'cover'  => (string)($music['cover'] ?? ''),
                'url'    => (string)($music['url'] ?? ''),
            ],
            'images'       => $pics,
            'video_backup' => array_values(array_filter(array_map('strval', $backup))),
            'live'         => $live,
            'type'         => $type,
            'author'       => $data['author'] ?? null,
            'source'       => (string)($data['source'] ?? ''),
        ];

        // 图集需有图片，视频需有直链，实况需有图片或实况视频
        if ($result['type'] === 2) {
            if (empty($result['images'])) {
                $this->apiError('图集未返回图片');
            }
        } elseif ($result['type'] === 3) {
            if (empty($result['images']) && empty($result['live'])) {
                $this->apiError('实况未返回内容');
            }
        } elseif ($result['video_url'] === '') {
            $msg = (string)($json['msg'] ?? '');
            throw new RuntimeException($msg !== '' ? $msg : '未获取到无水印视频地址');
        }

        return $result;
    }

    /**
     * 解析主页/多作品列表返回（如 dyzy 抖音主页解析）
     * 返回标准结果中的 list 字段，供前端渲染作品列表
     */
    private function parseList(array $items)
    {
        $list = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $rawType = $it['type'] ?? 'video';
            if (is_numeric($rawType)) {
                $type = (int)$rawType;
                if ($type <= 0) {
                    $type = 1;
                }
            } else {
                $t = strtolower((string)$rawType);
                $isImg = strpos($t, 'image') !== false || strpos($t, 'pic') !== false || strpos($t, 'photo') !== false;
                $type = $isImg ? 2 : 1;
            }
            $video  = (string)($it['url'] ?? $it['playAddr'] ?? '');
            $cover  = (string)($it['cover'] ?? '');
            $title  = (string)($it['desc'] ?? $it['title'] ?? '');
            $images = $it['images'] ?? $it['pics'] ?? null;
            $images = is_array($images) ? array_values(array_filter(array_map('strval', $images))) : [];
            $author = $it['author'] ?? null;
            if (!is_array($author)) {
                $author = (is_scalar($author) && (string)$author !== '') ? ['nickname' => (string)$author] : null;
            }
            $list[] = [
                'title'     => $title,
                'cover'     => $cover,
                'video_url' => $video,
                'images'    => $images,
                'type'      => $type,
                'author'    => $author,
                'share_url' => (string)($it['share_url'] ?? ''),
            ];
        }
        $list = array_values(array_filter($list, function ($v) {
            return $v['video_url'] !== '' || !empty($v['images']);
        }));
        if (empty($list)) {
            $this->apiError('主页未返回作品');
        }

        return [
            'platform'     => $this->api['name'] ?? '自定义接口',
            'title'        => '',
            'desc'         => '',
            'cover'        => '',
            'video_url'    => '',
            'music'        => ['title' => '', 'author' => '', 'cover' => '', 'url' => ''],
            'images'       => [],
            'video_backup' => [],
            'live'         => [],
            'type'         => 0,
            'author'       => null,
            'source'       => '',
            'list'         => $list,
        ];
    }

    /** 从字符串或对象数组中提取 URL 列表 */
    private static function extractUrls($val)
    {
        if (is_string($val) && preg_match('#^https?://#i', $val)) {
            return [$val];
        }
        if (!is_array($val)) {
            return [];
        }
        $out = [];
        foreach ($val as $item) {
            if (is_string($item) && preg_match('#^https?://#i', $item)) {
                $out[] = $item;
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            foreach (['url', 'pic', 'src', 'image', 'playAddr', 'addr'] as $k) {
                $u = $item[$k] ?? '';
                if (is_string($u) && preg_match('#^https?://#i', $u)) {
                    $out[] = $u;
                    break;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** 从对象数组的指定字段提取 URL */
    private static function extractUrlsFromItems($val, array $keys)
    {
        if (!is_array($val)) {
            return [];
        }
        $out = [];
        foreach ($val as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ($keys as $k) {
                $u = $item[$k] ?? '';
                if (is_string($u) && preg_match('#^https?://#i', $u)) {
                    $out[] = $u;
                    break;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** 取字段：优先用映射字段名，映射为空则用默认字段名 */
    private static function field($data, $mapping, $default)
    {
        $key = trim((string)$mapping);
        if ($key === '') {
            $key = $default;
        }
        $val = $data[$key] ?? '';
        return is_scalar($val) ? (string)$val : '';
    }
    private static function buildRequestUrl($apiUrl, $url)
    {
        $parts = parse_url($apiUrl);
        if ($parts === false || empty($parts['host'])) {
            throw new RuntimeException('接口地址格式不正确');
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        // 强制把 url 参数设为当前分享链接
        $query['url'] = $url;

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';

        return $scheme . '://' . $parts['host'] . $port . $path . '?' . http_build_query($query);
    }
}
