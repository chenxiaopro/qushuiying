<?php
/**
 * 易支付平台全局配置
 * 安装向导完成安装后会生成 app/config.local.php 覆盖默认值
 */

$GLOBALS['pay_config'] = [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'pay',
        'user' => 'pay',
        'pass' => '',
    ],
    'site_url' => '',
    'debug'    => false,
];

$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        foreach ($local as $k => $v) {
            if (is_array($v) && isset($GLOBALS['pay_config'][$k]) && is_array($GLOBALS['pay_config'][$k])) {
                $GLOBALS['pay_config'][$k] = array_merge($GLOBALS['pay_config'][$k], $v);
            } else {
                $GLOBALS['pay_config'][$k] = $v;
            }
        }
    }
}

function pcfg($key, $default = null)
{
    $parts = explode('.', $key);
    $v = $GLOBALS['pay_config'];
    foreach ($parts as $p) {
        if (is_array($v) && array_key_exists($p, $v)) {
            $v = $v[$p];
        } else {
            return $default;
        }
    }
    return $v;
}
