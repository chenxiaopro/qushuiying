<?php
/**
 * 微信公众号服务器配置入口
 * GET：URL 验证（echostr）
 * POST：接收消息/事件并回复
 */

require_once __DIR__ . '/../app/init.php';
require_once __DIR__ . '/../app/WechatMp.php';

$token = trim((string)setting('wxmp_token', ''));
$signature = (string)($_GET['signature'] ?? '');
$timestamp = (string)($_GET['timestamp'] ?? '');
$nonce = (string)($_GET['nonce'] ?? '');
$echostr = (string)($_GET['echostr'] ?? '');

if ($token === '' || $signature === '' || $timestamp === '' || $nonce === '') {
    http_response_code(400);
    echo 'invalid';
    exit;
}

if (!WechatMp::verifySignature($token, $signature, $timestamp, $nonce)) {
    http_response_code(403);
    echo 'invalid signature';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo $echostr;
    exit;
}

$raw = (string)file_get_contents('php://input');
$msg = WechatMp::parseXml($raw);
if (!$msg) {
    echo 'success';
    exit;
}

try {
    $reply = WechatMp::handleMessage($msg);
    header('Content-Type: text/xml; charset=utf-8');
    echo $reply === '' ? 'success' : $reply;
} catch (Throwable $e) {
    error_log('[wm-wxmp] ' . $e->getMessage());
    echo 'success';
}
