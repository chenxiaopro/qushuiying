<?php
/**
 * 易支付同步跳转入口（GET，平台直接回调）
 * 校验签名后跳回首页展示支付结果
 */

require_once __DIR__ . '/../app/init.php';
require_once __DIR__ . '/../app/payment/Epay.php';

$orderSn = (string)($_GET['out_trade_no'] ?? '');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$url = cfg('site_url') ?: (($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1'));

if (Epay::enabled()) {
    try {
        $epay = new Epay(setting('epay_api'), setting('epay_pid'), setting('epay_public_key'), setting('epay_private_key'));
        if (!$epay->verify($_GET)) {
            header('Location: ' . $url . '/?paid=' . urlencode($orderSn) . '&fail=1');
            exit;
        }
    } catch (Throwable $e) {
        // 验签异常也跳回首页，由异步通知保证最终入账
    }
}

header('Location: ' . $url . '/?paid=' . urlencode($orderSn));
exit;
