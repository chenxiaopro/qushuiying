<?php
/**
 * 通用函数
 */

/** JSON 输出并结束 */
function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok($data = [])
{
    json_out(['code' => 0, 'msg' => 'ok', 'data' => $data]);
}

function fail($msg, $code = 1, $http = 200)
{
    json_out(['code' => $code, 'msg' => $msg], $http);
}

/** 生产环境隐藏内部异常细节 */
function fail_safe($e, $fallback = '服务暂时不可用，请稍后重试')
{
    if (cfg('debug')) {
        fail('服务器异常：' . $e->getMessage(), 500);
    }
    error_log('[wm] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    fail($fallback, 500);
}

/** CSRF 令牌 */
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check()
{
    $token = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sess = (string)($_SESSION['csrf_token'] ?? '');
    if ($sess === '' || $token === '' || !hash_equals($sess, $token)) {
        fail('会话已过期，请刷新页面后重试', 403);
    }
}

/**
 * 基于 IP 的简易频率限制（文件存储）
 * @param string $action 动作名
 * @param int $max 窗口内最大次数
 * @param int $window 窗口秒数
 */
function rate_limit_ip($action, $max, $window)
{
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', client_ip());
    $file = sys_get_temp_dir() . '/wm_rl_' . hash('sha256', $action . '|' . $ip);
    $now = time();
    $hits = [];
    $fp = fopen($file, 'c+');
    if ($fp === false) {
        return;
    }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    if ($raw !== false && $raw !== '') {
        $hits = json_decode($raw, true) ?: [];
    }
    $hits = array_values(array_filter($hits, function ($t) use ($now, $window) {
        return (int)$t > ($now - $window);
    }));
    if (count($hits) >= $max) {
        flock($fp, LOCK_UN);
        fclose($fp);
        fail('操作过于频繁，请稍后再试', 429);
    }
    $hits[] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($hits));
    flock($fp, LOCK_UN);
    fclose($fp);
}

/** 安全获取请求参数 */
function input($key, $default = null)
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return $v;
}

/** 客户端 IP */
function client_ip()
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/** 生成唯一订单号 */
function gen_order_sn()
{
    return date('YmdHis') . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
}

/** 生成卡密 */
function gen_card()
{
    return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 16));
}

/** 简单文本脱敏显示 */
function mask_str($s, $head = 2, $tail = 2)
{
    $len = mb_strlen($s);
    if ($len <= $head + $tail) {
        return mb_substr($s, 0, 1) . '***';
    }
    return mb_substr($s, 0, $head) . '***' . mb_substr($s, $len - $tail);
}

/** curl GET */
function http_get($url, $headers = [], $timeout = 15)
{
    return http_request('GET', $url, null, $headers, $timeout);
}

/** curl POST (json body 或 表单) */
function http_post($url, $data, $headers = [], $timeout = 15, $asJson = false)
{
    $body = $asJson ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : http_build_query($data);
    return http_request('POST', $url, $body, $headers, $timeout, $asJson);
}

function http_request($method, $url, $body = null, $headers = [], $timeout = 15, $asJson = false)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
    ]);
    $hs = array_merge(['Accept: */*'], $headers);
    if ($body !== null) {
        if ($method === 'POST') {
            $hs[] = $asJson
                ? 'Content-Type: application/json; charset=utf-8'
                : 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hs);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP请求失败: ' . $err);
    }
    curl_close($ch);
    return $resp;
}

/** 重定向到 finalUrl，返回最终 url */
function get_final_url($url, $timeout = 12)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
    ]);
    curl_exec($ch);
    $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return $final ?: $url;
}

/** 从分享文本中提取第一个 http(s) 链接（自动裁剪口令中的乱码尾巴，支持无协议短链） */
function extract_url($text)
{
    $text = (string)$text;
    // 清理零宽字符、全角空格等复制时常见的不可见字符
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{3000}\x{00A0}]/u', '', $text);

    // 尾部常见标点/符号，从提取结果末尾裁剪掉
    $tail = '，。,.!！?？;；:：)）】》》》"\'#@';

    // 1. 带协议的 URL：http(s)://...
    if (preg_match('#https?://[^\s\x{4e00}-\x{9fa5}"\'<>，。！？；：、]+#u', $text, $m)) {
        return rtrim($m[0], $tail);
    }

    // 2. 无协议短链：v.douyin.com/xxx/ 、 www.xxx.com/xxx 等
    if (preg_match('#(?:[a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(?:/[^\s\x{4e00}-\x{9fa5}"\'<>，。！？；：、]*)+#u', $text, $m)) {
        return 'https://' . rtrim($m[0], $tail);
    }

    return null;
}

/** 判断主机是否为内网/回环/保留地址（用于 SSRF 防护） */
function is_private_host($host)
{
    $host = strtolower(trim((string)$host));
    if ($host === '') {
        return true;
    }
    // 去除 IPv6 括号
    $host = trim($host, '[]');
    $ip = filter_var($host, FILTER_VALIDATE_IP);
    if ($ip === false) {
        $ip = gethostbyname($host);
        if ($ip === $host || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true; // 无法解析为合法 IP
        }
    }
    // 无私有/保留段才放行
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/** 平台标识 -> 中文名 */
function platform_name($p)
{
    $map = [
        'douyin'      => '抖音',
        'kuaishou'    => '快手',
        'xiaohongshu' => '小红书',
        'bilibili'    => '哔哩哔哩',
        'shipinhao'   => '视频号',
        'weibo'       => '微博',
        'toutiao'     => '今日头条',
        'pipixia'     => '皮皮虾',
        'izuiyou'     => '最右',
        'jimeng'      => '即梦',
        'doubao'      => '豆包',
        'qianwen'     => '千问',
        'weishi'      => '微视',
        'huoshan'     => '火山',
        'pinduoduo'   => '拼多多',
        'api'         => '自定义接口',
    ];
    return $map[$p] ?? ($p ?: '未知');
}

/** 识别分享链接对应的平台标识（slug），无法识别返回空字符串 */
function detect_platform_slug($text)
{
    $text = (string)$text;
    $url = extract_url($text);
    $host = strtolower((string)parse_url($url ?: '', PHP_URL_HOST));
    if ($host === '') {
        $host = strtolower($text);
    }

    $map = [
        'douyin'      => ['douyin.com', 'iesdouyin.com'],
        'kuaishou'    => ['kuaishou.com', 'gifshow.com', 'chenzhongtech.com'],
        'xiaohongshu' => ['xiaohongshu.com', 'xhslink.com'],
        'bilibili'    => ['bilibili.com', 'b23.tv'],
        'shipinhao'   => ['channels.weixin.qq.com', 'finder.video.qq.com'],
        'weibo'       => ['weibo.com', 'weibo.cn'],
        'toutiao'     => ['toutiao.com'],
        'pipixia'     => ['pipix.com', 'pipixia.com'],
        'izuiyou'     => ['izuiyou.com', 'zuiyou.com', 'xiaoying.tv'],
        'jimeng'      => ['jimeng.jianying.com'],
        'doubao'      => ['doubao.com'],
        'qianwen'     => ['tongyi.com', 'tongyi.aliyun.com'],
        'huoshan'     => ['huoshan.com'],
        'weishi'      => ['weishi.qq.com'],
    ];

    foreach ($map as $slug => $domains) {
        foreach ($domains as $d) {
            if ($host === $d || substr($host, -(strlen($d) + 1)) === '.' . $d) {
                return $slug;
            }
        }
    }

    // 回退：取主域名主体作为标识
    $parts = explode('.', $host);
    $n = count($parts);
    if ($n >= 2) {
        return $parts[$n - 2];
    }
    return $host;
}

/** 根据目标 URL 推断防盗链 Referer */
function referer_for_url($url)
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
    $referer = $scheme . '://' . $host . '/';
    $refererMap = [
        'douyinvod.com'     => 'https://www.douyin.com/',
        'douyinpic.com'     => 'https://www.douyin.com/',
        'iesdouyin.com'     => 'https://www.douyin.com/',
        'douyinstatic.com'  => 'https://www.douyin.com/',
        'xhscdn.com'        => 'https://www.xiaohongshu.com/',
        'xhsvc.cn'          => 'https://www.xiaohongshu.com/',
        'xiaohongshu.com'   => 'https://www.xiaohongshu.com/',
    ];
    foreach ($refererMap as $dom => $ref) {
        if (stripos($host, $dom) !== false) {
            $referer = $ref;
            break;
        }
    }
    return $referer;
}

/** 从 URL 推断图片扩展名 */
function img_ext_from_url($url)
{
    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
        $ext = 'jpg';
    }
    return $ext === 'jpeg' ? 'jpg' : $ext;
}

/** 纯 PHP 打包 zip（stored 不压缩，图片本身已压缩） */
function zip_files($files)
{
    $out = '';
    $central = '';
    $offset = 0;
    $count = 0;
    foreach ($files as $f) {
        $name = preg_replace('/[^A-Za-z0-9._\x{4e00}-\x{9fa5}-]/u', '_', (string)$f['name']);
        $data = (string)$f['data'];
        $crc = crc32($data);
        $size = strlen($data);
        $namelen = strlen($name);

        $local = pack('V', 0x04034b50);
        $local .= pack('v', 20);
        $local .= pack('v', 0x0800);
        $local .= pack('v', 0);
        $local .= pack('v', 0);
        $local .= pack('v', 0);
        $local .= pack('V', $crc);
        $local .= pack('V', $size);
        $local .= pack('V', $size);
        $local .= pack('v', $namelen);
        $local .= pack('v', 0);
        $local .= $name . $data;
        $out .= $local;

        $cd = pack('V', 0x02014b50);
        $cd .= pack('v', 20);
        $cd .= pack('v', 20);
        $cd .= pack('v', 0x0800);
        $cd .= pack('v', 0);
        $cd .= pack('v', 0);
        $cd .= pack('v', 0);
        $cd .= pack('V', $crc);
        $cd .= pack('V', $size);
        $cd .= pack('V', $size);
        $cd .= pack('v', $namelen);
        $cd .= pack('v', 0);
        $cd .= pack('v', 0);
        $cd .= pack('v', 0);
        $cd .= pack('v', 0);
        $cd .= pack('V', 0);
        $cd .= pack('V', $offset);
        $cd .= $name;
        $central .= $cd;

        $offset += strlen($local);
        $count++;
    }

    $out .= $central;
    $out .= pack('V', 0x06054b50);
    $out .= pack('v', 0);
    $out .= pack('v', 0);
    $out .= pack('v', $count);
    $out .= pack('v', $count);
    $out .= pack('V', strlen($central));
    $out .= pack('V', $offset);
    $out .= pack('v', 0);
    return $out;
}
