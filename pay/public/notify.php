<?php
/**
 * 上游支付通道异步通知入口
 *
 * 通道通过 ?channel=xxx 指定，各通道自行校验通知并返回平台订单号，
 * 成功后回写订单状态并通知商户。
 */

require_once __DIR__ . '/../app/init.php';
require_once __DIR__ . '/../app/ChannelFactory.php';
require_once __DIR__ . '/../app/OrderService.php';

$channelCode = (string)($_GET['channel'] ?? '');

try {
    $channel = ChannelFactory::make($channelCode);
    $chRow = PDB::one('SELECT * FROM pay_channels WHERE code=? AND enabled=1', [$channelCode]);
    if (!$chRow) {
        echo 'fail';
        exit;
    }
    $config = $chRow['config'] ? json_decode($chRow['config'], true) : [];
    $config = is_array($config) ? $config : [];

    $tradeNo = $channel->verifyNotify($config);
    if (!$tradeNo) {
        echo 'fail';
        exit;
    }

    $ok = OrderService::markPaid($tradeNo);
    echo $ok ? 'success' : 'fail';
    exit;
} catch (Exception $e) {
    error_log('[pay] notify error: ' . $e->getMessage());
    echo 'fail';
    exit;
}
