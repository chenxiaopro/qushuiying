<?php
/**
 * 上游易支付转发通道
 * 将订单转发到第三方彩虹易支付平台
 */

require_once __DIR__ . '/ChannelInterface.php';

class UpstreamEpay implements ChannelInterface
{
    public static function code() { return 'upstream_epay'; }
    public static function label() { return '上游易支付转发'; }

    public function create($order, $config)
    {
        $api = rtrim((string)($config['api_url'] ?? ''), '/');
        $pid = (string)($config['pid'] ?? '');
        $key = (string)($config['key'] ?? '');
        $type = (string)($config['type'] ?? 'alipay');
        if ($api === '' || $pid === '' || $key === '') {
            throw new RuntimeException('上游易支付配置不完整');
        }
        $params = [
            'pid'          => $pid,
            'type'         => $type,
            'out_trade_no' => (string)$order['trade_no'],
            'notify_url'   => (string)($config['notify_url'] ?? ''),
            'return_url'   => (string)($config['return_url'] ?? ''),
            'name'         => (string)($order['name'] ?: '订单支付'),
            'money'        => sprintf('%.2f', $order['money']),
        ];
        $params['sign'] = $this->sign($params, $key);
        $params['sign_type'] = 'MD5';
        $submit = $api . '/submit.php';
        $res = p_http_get($submit . '?' . http_build_query($params), [], 15);
        $url = $res['body'];
        $url = trim($url);
        if (preg_match('~^https?://~i', $url)) {
            return ['type' => 'redirect', 'data' => $url, 'pay_trade_no' => ''];
        }
        $json = json_decode($url, true);
        if (is_array($json) && !empty($json['qrcode'])) {
            return ['type' => 'qrcode', 'data' => (string)$json['qrcode'], 'pay_trade_no' => ''];
        }
        if (is_array($json) && !empty($json['url'])) {
            return ['type' => 'redirect', 'data' => (string)$json['url'], 'pay_trade_no' => ''];
        }
        throw new RuntimeException('上游易支付下单失败：' . (is_array($json) ? ($json['msg'] ?? '未知错误') : $url));
    }

    public function verifyNotify(array $config)
    {
        $params = $_GET;
        if (empty($params['pid']) || empty($params['trade_no']) || empty($params['out_trade_no'])) {
            return null;
        }
        $sign = (string)($params['sign'] ?? '');
        unset($params['sign'], $params['sign_type']);
        $key = (string)($config['key'] ?? '');
        $calc = $this->sign($params, $key);
        if (!hash_equals(strtoupper($sign), $calc)) {
            return null;
        }
        if (($params['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            return null;
        }
        return (string)$params['out_trade_no'];
    }

    private function sign(array $params, $key)
    {
        $params = array_filter($params, function ($k) {
            return $k !== 'sign' && $k !== 'sign_type';
        }, ARRAY_FILTER_USE_KEY);
        $params['key'] = $key;
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $pairs[] = $k . '=' . $v;
        }
        return strtoupper(md5(implode('&', $pairs)));
    }
}
