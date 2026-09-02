<?php
/**
 * 微信 Native 扫码支付
 */

require_once __DIR__ . '/WechatV3Base.php';

class WechatNative extends WechatV3Base
{
    public static function code() { return 'wechat_native'; }
    public static function label() { return '微信Native扫码'; }

    protected function apiPath() { return '/v3/pay/transactions/native'; }
    protected function resultType() { return 'qrcode'; }

    protected function buildBody($order, $config, $extra = [])
    {
        return [
            'appid'        => (string)($config['appid'] ?? ''),
            'mchid'        => (string)($config['mchid'] ?? ''),
            'description'  => (string)($order['name'] ?: '订单支付'),
            'out_trade_no' => (string)$order['trade_no'],
            'notify_url'   => (string)($config['notify_url'] ?? ''),
            'amount'       => ['total' => (int)round($order['money'] * 100), 'currency' => 'CNY'],
        ];
    }

    protected function parseResult(array $json)
    {
        return ['type' => 'qrcode', 'data' => (string)$json['code_url'], 'pay_trade_no' => (string)($json['transaction_id'] ?? '')];
    }
}
