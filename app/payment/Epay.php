<?php
/**
 * 易支付（彩虹/异次元通用接口）对接
 *
 * 支付流程：
 *   1. 创建订单 -> 调用 submitUrl() 获得支付跳转地址
 *   2. 用户支付 -> 支付平台异步通知 notifyUrl -> 校验签名 -> 更新订单并给用户加点
 *   3. 同步跳转 returnUrl 展示结果
 *
 * 后台设置中填写 易支付API地址、商户ID、商户KEY 并开启开关即可生效。
 */

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../db.php';

class Epay
{
    private $api;
    private $pid;
    private $key;

    public function __construct($api, $pid, $key)
    {
        $this->api = rtrim($api, '/');
        $this->pid = $pid;
        $this->key = $key;
    }

    public static function enabled()
    {
        return setting('epay_enabled') == '1'
            && setting('epay_api')
            && setting('epay_pid')
            && setting('epay_key');
    }

    /** 支持的支付方式列表 */
    public static function payTypes()
    {
        $t = setting('epay_pay_types', 'alipay,wxpay');
        $map = ['alipay' => '支付宝', 'wxpay' => '微信'];
        $out = [];
        foreach (explode(',', $t) as $p) {
            $p = trim($p);
            if (isset($map[$p])) {
                $out[$p] = $map[$p];
            }
        }
        return $out ?: ['alipay' => '支付宝', 'wxpay' => '微信'];
    }

    /** 生成支付跳转地址 */
    public function submitUrl($orderSn, $money, $type, $name)
    {
        $notifyUrl = $this->siteUrl() . '/api.php?action=pay_notify';
        $returnUrl = $this->siteUrl() . '/api.php?action=pay_return';
        $params = [
            'pid'          => $this->pid,
            'type'         => $type,
            'out_trade_no' => $orderSn,
            'notify_url'   => $notifyUrl,
            'return_url'   => $returnUrl,
            'name'         => $name,
            'money'        => sprintf('%.2f', $money),
        ];
        $params['sign'] = $this->sign($params);
        $params['sign_type'] = 'MD5';
        return $this->api . '/submit.php?' . http_build_query($params);
    }

    /** 彩虹易支付标准签名：参数按 key 升序拼接后附商户密钥，md5 大写 */
    private function sign(array $params)
    {
        $params = array_filter($params, function ($k) {
            return $k !== 'sign' && $k !== 'sign_type';
        }, ARRAY_FILTER_USE_KEY);
        $params['key'] = $this->key;
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

    /** 校验异步通知，成功返回 ['order_sn','money','type','trade_no','trade_status']，失败返回 null */
    public function verifyNotify($data)
    {
        $sign = $data['sign'] ?? '';
        $pid = $data['pid'] ?? '';
        $tradeNo = $data['trade_no'] ?? '';
        $orderSn = $data['out_trade_no'] ?? '';
        $type = $data['type'] ?? '';
        $money = $data['money'] ?? '';

        if ($pid !== $this->pid) {
            return null;
        }
        $allowed = ['pid', 'trade_no', 'out_trade_no', 'type', 'name', 'money', 'trade_status', 'param'];
        $clean = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $clean[$k] = $data[$k];
            }
        }
        $expected = $this->sign($clean);
        if (strtoupper((string)$sign) !== $expected) {
            return null;
        }
        return [
            'order_sn'     => $orderSn,
            'money'        => $money,
            'type'         => $type,
            'trade_no'     => $tradeNo,
            'trade_status' => $data['trade_status'] ?? '',
        ];
    }

    private function siteUrl()
    {
        $url = cfg('site_url');
        if ($url) {
            return rtrim($url, '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $https ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
    }
}
