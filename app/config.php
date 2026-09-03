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
    // 当前程序版本号（发版时由开发者维护，站点「当前版本」与「历史版本」同步显示）
    'app_version' => 'v1.3.0',
    'debug'    => false,
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
