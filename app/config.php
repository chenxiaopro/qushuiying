<?php
/**
 * 全局配置
 * 安装向导(public/install.php)完成安装后会生成 app/config.local.php 覆盖默认值
 */

$GLOBALS['config'] = [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'wm',
        'user' => 'wm',
        'pass' => '',
    ],
    // 站点访问地址，例如 https://wm.example.com （可留空自动识别）
    'site_url' => '',
    // 初始程序版本号；站点当前版本由后台「历史版本」新增记录时按类型自动递增
    'app_version' => 'v1.3.0',
    'debug'    => false,
    // 是否信任反向代理传递的 X-Forwarded-For / X-Real-IP 头（用于获取真实客户端 IP）
    // 仅在站点部署于可信反代（如 Nginx 已强制覆写该头）之后才应设为 true，否则可被伪造绕过限流
    'trust_proxy' => false,
    // 下载签名密钥（用于生成 download.php 的下载令牌，生产环境请务必覆盖为随机字符串）
    'app_secret' => '',
    // 管理员登录用 Cookie 有效期(秒)
    'session_ttl' => 7 * 86400,
];

$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        foreach ($local as $k => $v) {
            if (is_array($v) && isset($GLOBALS['config'][$k]) && is_array($GLOBALS['config'][$k])) {
                $GLOBALS['config'][$k] = array_merge($GLOBALS['config'][$k], $v);
            } else {
                $GLOBALS['config'][$k] = $v;
            }
        }
    }
}

function cfg($key, $default = null)
{
    $parts = explode('.', $key);
    $v = $GLOBALS['config'];
    foreach ($parts as $p) {
        if (is_array($v) && array_key_exists($p, $v)) {
            $v = $v[$p];
        } else {
            return $default;
        }
    }
    return $v;
}
