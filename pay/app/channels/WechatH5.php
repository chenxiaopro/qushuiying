<?php
/**
 * 微信 H5 手机网页支付
 */

require_once __DIR__ . '/WechatV3Base.php';

class WechatH5 extends WechatV3Base
{
    public static function code() { return 'wechat_h5'; }
    public static function label() { return '微信H5支付'; }

    protected function apiPath() { return '/v3/pay/transactions/h5'; }
    protected function resultType() { return 'redirect'; }

    protected function buildBody($order, $config, $extra = [])
    {
        return [
            'appid'        => (string)($config['appid'] ?? ''),
            'mchid'        => (string)($config['mchid'] ?? ''),
            'description'  => (string)($order['name'] ?: '订单支付'),
            'out_trade_no' => (string)$order['trade_no'],
            'notify_url'   => (string)($config['notify_url'] ?? ''),
            'amount'       => ['total' => (int)round($order['money'] * 100), 'currency' => 'CNY'],
            'scene_info'   => [
                'payer_client_ip' => (string)($extra['client_ip'] ?? p_client_ip()),
                'h5_info'         => ['type' => 'Wap'],
            ],
        ];
    }

    protected function parseResult(array $json)
    {
        return ['type' => 'redirect', 'data' => (string)$json['h5_url'], 'pay_trade_no' => (string)($json['transaction_id'] ?? '')];
    }
}
