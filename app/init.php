<?php
/**
 * 公共启动：时区、安全头、错误显示、会话
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

if (!cfg('debug')) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}
