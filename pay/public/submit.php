<?php
/**
 * 易支付下单接口（彩虹易支付协议）
 *
 * GET/POST 参数：
 *   pid          商户号
 *   type         支付方式 (alipay / wxpay / alipay_f2f / wechat_native / wechat_h5 / wechat_jsapi ...)
 *   out_trade_no 商户订单号
 *   notify_url   异步通知地址
 *   return_url   同步回跳地址
 *   name         商品名称
 *   money        金额
 *   sign         签名 (MD5)
 *   sign_type    签名类型 (MD5)
 *
 * 返回：收银台跳转地址（自动跳转）
 */

require_once __DIR__ . '/../app/init.php';
require_once __DIR__ . '/../app/PaySign.php';
require_once __DIR__ . '/../app/OrderService.php';

try {
    $pid = (string)p_input('pid');
    $merchant = PDB::one('SELECT * FROM pay_merchants WHERE pid=?', [$pid]);
    if (!$merchant || (int)$merchant['status'] !== 1) {
        p_fail('商户不存在或已被禁用');
    }

    $params = $_GET + $_POST;
    if (!PaySign::verify($params, $merchant['secret'])) {
        p_fail('签名错误');
    }

    $money = (float)($params['money'] ?? 0);
    if ($money <= 0) {
        p_fail('金额不合法');
    }
    $outTradeNo = (string)($params['out_trade_no'] ?? '');
    if ($outTradeNo === '') {
        p_fail('商户订单号不能为空');
    }
    $payType = (string)($params['type'] ?? 'alipay');

    $channel = ChannelFactory::resolve($payType, (int)($params['channel_id'] ?? 0));
    $tradeNo = p_gen_trade_no();

    PDB::execute(
        'INSERT INTO pay_orders(trade_no,out_trade_no,merchant_id,channel_id,pay_type,name,money,status,notify_url,return_url,created_at) VALUES(?,?,?,?,?,?,?,0,?,?,NOW())',
        [
            $tradeNo,
            $outTradeNo,
            (int)$merchant['id'],
            (int)$channel['id'],
            $payType,
            (string)($params['name'] ?? ''),
            sprintf('%.2f', $money),
            (string)($params['notify_url'] ?? ''),
            (string)($params['return_url'] ?? ''),
        ]
    );

    $cashier = p_site_url() . '/cashier.php?trade_no=' . urlencode($tradeNo);
    header('Location: ' . $cashier, true, 302);
    echo '<script>location.href="' . htmlspecialchars($cashier, ENT_QUOTES) . '";</script>';
    exit;
} catch (Exception $e) {
    p_fail_safe($e, '下单失败');
}
