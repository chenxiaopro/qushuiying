<?php
/**
 * 支付宝当面付（扫码支付）
 * 使用 alipay.trade.precreate 预下单，返回二维码内容
 */

require_once __DIR__ . '/ChannelInterface.php';

class AlipayF2F implements ChannelInterface
{
    const GATEWAY = 'https://openapi.alipay.com/gateway.do';

    public static function code() { return 'alipay_f2f'; }
    public static function label() { return '支付宝当面付'; }

    public function create($order, $config)
    {
        $appId = trim((string)($config['app_id'] ?? ''));
        $biz = [
            'out_trade_no' => $order['trade_no'],
            'total_amount' => sprintf('%.2f', $order['money']),
            'subject'      => (string)($order['name'] ?: '订单支付'),
            'timeout_express' => '30m',
        ];
        $resp = $this->request($config, 'alipay.trade.precreate', $biz, $appId);
        if (!isset($resp['alipay_trade_precreate_response'])) {
            throw new RuntimeException('支付宝下单失败：' . ($resp['error_response']['sub_msg'] ?? '未知错误'));
        }
        $r = $resp['alipay_trade_precreate_response'];
        if (($r['code'] ?? '') !== '10000') {
            throw new RuntimeException('支付宝下单失败：' . ($r['sub_msg'] ?? $r['msg'] ?? '未知错误'));
        }
        return [
            'type'         => 'qrcode',
            'data'         => (string)$r['qr_code'],
            'pay_trade_no' => (string)($r['trade_no'] ?? ''),
        ];
    }

    public function verifyNotify(array $config)
    {
        $params = $_POST;
        $sign = (string)($params['sign'] ?? '');
        if ($sign === '') {
            return null;
        }
        $signType = (string)($params['sign_type'] ?? 'RSA2');
        unset($params['sign'], $params['sign_type']);
        $content = self::buildSignStr($params);
        $pub = (string)($config['alipay_public_key'] ?? '');
        $ok = $signType === 'RSA'
            ? openssl_verify($content, base64_decode($sign), $pub, OPENSSL_ALGO_SHA1)
            : openssl_verify($content, base64_decode($sign), $pub, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            return null;
        }
        if (($params['trade_status'] ?? '') !== 'TRADE_SUCCESS' && ($params['trade_status'] ?? '') !== 'TRADE_FINISHED') {
            return null;
        }
        return (string)($params['out_trade_no'] ?? '');
    }

    private function request($config, $method, $biz, $appId)
    {
        $common = [
            'app_id'      => $appId,
            'method'      => $method,
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'notify_url'  => (string)($config['notify_url'] ?? ''),
            'biz_content' => json_encode($biz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $common['sign'] = $this->sign($common, (string)($config['merchant_private_key'] ?? ''));
        $res = p_http_post(self::GATEWAY, $common, [], 20);
        return json_decode($res['body'], true) ?: [];
    }

    private function sign($params, $privateKey)
    {
        $content = self::buildSignStr($params);
        openssl_sign($content, $sig, $privateKey, OPENSSL_ALGO_SHA256);
        return base64_encode($sig);
    }

    private static function buildSignStr($params)
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null || $k === 'sign') {
                continue;
            }
            $pairs[] = $k . '=' . $v;
        }
        return implode('&', $pairs);
    }
}
