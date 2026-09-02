<?php
/**
 * 微信 JSAPI 公众号支付
 */

require_once __DIR__ . '/WechatV3Base.php';

class WechatJsapi extends WechatV3Base
{
    public static function code() { return 'wechat_jsapi'; }
    public static function label() { return '微信JSAPI支付'; }

    protected function apiPath() { return '/v3/pay/transactions/jsapi'; }
    protected function resultType() { return 'jsapi'; }

    protected function buildBody($order, $config, $extra = [])
    {
        $openid = (string)($extra['openid'] ?? '');
        return [
            'appid'        => (string)($config['appid'] ?? ''),
            'mchid'        => (string)($config['mchid'] ?? ''),
            'description'  => (string)($order['name'] ?: '订单支付'),
            'out_trade_no' => (string)$order['trade_no'],
            'notify_url'   => (string)($config['notify_url'] ?? ''),
            'amount'       => ['total' => (int)round($order['money'] * 100), 'currency' => 'CNY'],
            'payer'        => ['openid' => $openid],
        ];
    }

    protected function parseResult(array $json)
    {
        return ['type' => 'jsapi', 'data' => (string)$json['prepay_id'], 'pay_trade_no' => (string)($json['transaction_id'] ?? '')];
    }
}
