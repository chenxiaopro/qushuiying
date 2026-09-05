<?php
/**
 * 代理下载 / 在线播放接口
 * 第三方视频/音乐直链存在跨域、防盗链问题，浏览器 <a download> 无法直接下载，
 * <video> 也无法直接播放（缺少正确的 Referer）。通过本站代理转发解决。
 *
 * 参数：
 *   url     目标资源地址（http/https）
 *   name    可选，保存文件名
 *   stream  可选，1 表示在线播放（内联输出 + 支持 Range），默认 0 表示强制下载
 */

require_once __DIR__ . '/../app/init.php';

$u = current_user();
if (!$u || (int)$u['status'] !== 1) {
    http_response_code(401);
    exit('请先登录');
}

// 流式转发不受 PHP 执行时间限制（宝塔默认 max_execution_time 可能只有几十秒）
set_time_limit(0);
// 关闭 PHP 输出缓冲，确保视频字节边收边发（Nginx 下配合 X-Accel-Buffering 直通）
while (ob_get_level() > 0) {
    ob_end_clean();
}

// 批量下载：图集打包 zip
if (input('batch', '') === '1') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    $urls = is_array($payload['urls'] ?? null) ? $payload['urls'] : [];
    $zipName = trim((string)($payload['name'] ?? 'download'));
    $zipName = preg_replace('/[^A-Za-z0-9._\x{4e00}-\x{9fa5}-]/u', '_', $zipName) ?: 'download';

    $urls = array_values(array_filter(array_map('strval', $urls)));
    if (empty($urls)) {
        http_response_code(400);
        exit('缺少图片地址');
    }
    if (count($urls) > 50) {
        http_response_code(400);
        exit('文件数量过多');
    }

    $files = [];
    $idx = 0;
    foreach ($urls as $u) {
        $idx++;
        if (!preg_match('#^https?://#i', $u)) {
            continue;
        }
        $host = parse_url($u, PHP_URL_HOST);
        if (!$host || is_private_host($host)) {
            continue;
        }
        try {
            $data = http_get($u, ['Referer: ' . referer_for_url($u)], 30);
        } catch (RuntimeException $e) {
            continue;
        }
        if ($data === '' || $data === false) {
            continue;
        }
        $files[] = [
            'name' => sprintf('%d.%s', $idx, img_ext_from_url($u)),
            'data' => $data,
        ];
    }

    if (empty($files)) {
        http_response_code(502);
        exit('图片下载失败');
    }

    $zip = zip_files($files);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '.zip"; filename*=UTF-8\'\'' . rawurlencode($zipName . '.zip'));
    header('Content-Length: ' . strlen($zip));
    header('Cache-Control: no-store');
    echo $zip;
    exit;
}

$url    = trim((string)input('url', ''));
$name   = trim((string)input('name', ''));
$stream = input('stream', '0') === '1';

if ($url === '' || !preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    exit('参数错误');
}

// SSRF 防护：禁止内网/回环/保留地址
$host = parse_url($url, PHP_URL_HOST);
if (!$host || is_private_host($host)) {
    http_response_code(403);
    exit('禁止访问该地址');
}

// 防盗链 Referer：部分 CDN 校验来源页，映射到对应平台首页
$referer = referer_for_url($url);

// 推断扩展名与文件名
$path = parse_url($url, PHP_URL_PATH) ?: '/';
$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if ($ext === '' || !preg_match('/^[a-z0-9]{1,5}$/', $ext)) {
    $ext = 'mp4';
}
if ($name === '') {
    $name = pathinfo($path, PATHINFO_FILENAME) ?: 'download';
}
if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === '') {
    $name .= '.' . $ext;
}
// 清理文件名非法字符
$name = preg_replace('/[^A-Za-z0-9._\x{4e00}-\x{9fa5}-]/u', '_', $name);
$name = mb_substr($name, 0, 120);

$mimeMap = [
    'mp4'  => 'video/mp4',
    'm4v'  => 'video/x-m4v',
    'mov'  => 'video/quicktime',
    'mp3'  => 'audio/mpeg',
    'm4a'  => 'audio/x-m4a',
    'wav'  => 'audio/x-wav',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];
$fallbackCtype = $mimeMap[$ext] ?? 'application/octet-stream';

// 解析客户端 Range 请求（视频播放/拖动进度需要）
$range = null;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=\d*-\d*$/i', trim($_SERVER['HTTP_RANGE']))) {
    $range = trim($_SERVER['HTTP_RANGE']);
}

$upstreamStatus  = 200;
$upstreamCtype   = $fallbackCtype;
$upstreamClen    = null;
$upstreamCrange  = null;

$ch = curl_init();
curl_setopt_array($ch, http_ssl_opts(false) + [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 300,
    CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
    CURLOPT_REFERER        => $referer,
    CURLOPT_ENCODING       => 'identity',
    CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$upstreamStatus, &$upstreamCtype, &$upstreamClen, &$upstreamCrange) {
        $len = strlen($h);
        if (stripos($h, 'HTTP/') === 0) {
            $parts = explode(' ', trim($h));
            $upstreamStatus = (int)($parts[1] ?? 200);
        } elseif (stripos($h, 'Content-Type:') === 0) {
            $v = trim(substr($h, strlen('Content-Type:')));
            if ($v !== '') {
                $upstreamCtype = $v;
            }
        } elseif (stripos($h, 'Content-Length:') === 0) {
            $upstreamClen = (int)trim(substr($h, strlen('Content-Length:')));
        } elseif (stripos($h, 'Content-Range:') === 0) {
            $upstreamCrange = trim(substr($h, strlen('Content-Range:')));
        }
        return $len;
    },
]);
if ($range !== null) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Range: ' . $range]);
}

$headersSent = false;
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($c, $data) use (
    &$headersSent, &$upstreamStatus, &$upstreamCtype, &$upstreamClen, &$upstreamCrange, $stream, $name
) {
    if (!$headersSent) {
        http_response_code($upstreamStatus);
        header('Content-Type: ' . $upstreamCtype);
        if (!$stream) {
            header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        }
        header('Cache-Control: no-store');
        header('Accept-Ranges: bytes');
        // 告知 Nginx/Apache 不要缓冲该响应，视频流实时透传给浏览器
        header('X-Accel-Buffering: no');
        if ($upstreamCrange !== null) {
            header('Content-Range: ' . $upstreamCrange);
        }
        if ($upstreamClen !== null) {
            header('Content-Length: ' . $upstreamClen);
        }
        $headersSent = true;
    }
    echo $data;
    flush();
    return strlen($data);
});

curl_exec($ch);
curl_close($ch);
