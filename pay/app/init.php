<?php
/**
 * 易支付平台公共启动
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

date_default_timezone_set('Asia/Shanghai');

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 1; mode=block');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

if (!pcfg('debug')) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}
