<?php
/**
 * 易支付 V2 对接（SHA256WithRSA 签名）
 *
 * 支付流程：
 *   1. 创建订单 -> submitUrl() 获得支付跳转地址（页面跳转支付）
 *   2. 用户支付 -> 平台异步通知 pay_notify.php -> 验签 -> 更新订单并给用户加点
 *   3. 同步跳转 pay_return.php 展示结果
 *
 * 后台设置需填写：易支付API地址、商户ID、平台公钥、商户私钥 并开启开关。
 */

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../db.php';

class Epay
{
    private $api;
    private $pid;
    private $publicKey;
    private $privateKey;
    private $signType = 'RSA';

    public function __construct($api, $pid, $publicKey, $privateKey)
    {
        $this->api = rtrim($api, '/');
        $this->pid = $pid;
        $this->publicKey = trim((string)$publicKey);
        $this->privateKey = trim((string)$privateKey);
    }

    public static function enabled()
    {
        return setting('epay_enabled') == '1'
            && setting('epay_api')
            && setting('epay_pid')
            && setting('epay_public_key')
            && setting('epay_private_key');
    }

    /** 支持的支付方式列表 */
    public static function payTypes()
    {
        $t = setting('epay_pay_types', 'alipay,wxpay');
        $map = ['alipay' => '支付宝', 'wxpay' => '微信', 'qqpay' => 'QQ钱包', 'bank' => '云闪付', 'jdpay' => '京东支付'];
        $out = [];
        foreach (explode(',', $t) as $p) {
            $p = trim($p);
            if (isset($map[$p])) {
                $out[$p] = $map[$p];
            }
        }
        return $out ?: ['alipay' => '支付宝', 'wxpay' => '微信'];
    }

    /** 生成页面跳转支付地址（api/pay/submit） */
    public function submitUrl($orderSn, $money, $type, $name)
    {
        $params = [
            'type'         => $type,
            'notify_url'   => $this->siteUrl() . '/pay_notify.php',
            'return_url'   => $this->siteUrl() . '/pay_return.php',
            'out_trade_no' => $orderSn,
            'name'         => $name,
            'money'        => sprintf('%.2f', $money),
        ];
        $params = $this->buildRequestParam($params);
        return $this->api . '/api/pay/submit?' . http_build_query($params);
    }

    /** 校验异步通知，成功返回 ['order_sn','money','type','trade_no','trade_status']，失败返回 null */
    public function verifyNotify($data)
    {
        if (!$this->verify($data)) {
            return null;
        }
        if (($data['pid'] ?? '') !== $this->pid) {
            return null;
        }
        return [
            'order_sn'     => $data['out_trade_no'] ?? '',
            'money'        => $data['money'] ?? '',
            'type'         => $data['type'] ?? '',
            'trade_no'     => $data['trade_no'] ?? '',
            'trade_status' => $data['trade_status'] ?? '',
        ];
    }

    /** 查询订单支付状态（api/pay/query），返回订单数组或 null */
    public function queryOrder($outTradeNo = '', $tradeNo = '')
    {
        $params = [];
        if ($tradeNo !== '') {
            $params['trade_no'] = $tradeNo;
        } elseif ($outTradeNo !== '') {
            $params['out_trade_no'] = $outTradeNo;
        } else {
            return null;
        }
        $result = $this->execute('api/pay/query', $params);
        return is_array($result) ? $result : null;
    }

    /** 发起 API 请求（api/pay/create 等）并验签 */
    public function execute($path, $params)
    {
        $path = ltrim($path, '/');
        $url = $this->api . '/' . $path;
        $param = $this->buildRequestParam($params);
        $response = http_post($url, $param, [], 20, false);
        $arr = json_decode($response, true);
        if (is_array($arr) && ($arr['code'] ?? null) == 0) {
            if (!$this->verify($arr)) {
                throw new RuntimeException('返回数据验签失败');
            }
            return $arr;
        }
        throw new RuntimeException(is_array($arr) ? ($arr['msg'] ?? '请求失败') : '请求失败');
    }

    /** 构造请求参数（追加 pid、timestamp、sign、sign_type） */
    private function buildRequestParam($params)
    {
        $params['pid'] = $this->pid;
        $params['timestamp'] = (string)time();
        $params['sign'] = $this->rsaPrivateSign($this->getSignContent($params));
        $params['sign_type'] = $this->signType;
        return $params;
    }

    /** 验签（含时间戳校验，±300 秒） */
    public function verify($arr)
    {
        if (empty($arr) || empty($arr['sign'])) {
            return false;
        }
        if (empty($arr['timestamp']) || abs(time() - (int)$arr['timestamp']) > 300) {
            return false;
        }
        $sign = (string)$arr['sign'];
        return $this->rsaPublicVerify($this->getSignContent($arr), $sign);
    }

    /** 待签名字符串：剔除 sign/sign_type/空值/数组，按 key 升序 k=v&k=v 拼接 */
    private function getSignContent($params)
    {
        ksort($params);
        $signstr = '';
        foreach ($params as $k => $v) {
            if (is_array($v) || $this->isEmpty($v) || $k === 'sign' || $k === 'sign_type') {
                continue;
            }
            $signstr .= '&' . $k . '=' . $v;
        }
        return substr($signstr, 1);
    }

    private function isEmpty($value)
    {
        return $value === null || trim((string)$value) === '';
    }

    /** 商户私钥签名（SHA256WithRSA，输出 base64） */
    private function rsaPrivateSign($data)
    {
        $privateKey = $this->resolveKey($this->privateKey, ['PRIVATE KEY', 'RSA PRIVATE KEY']);
        if (!$privateKey) {
            throw new RuntimeException('签名失败：商户私钥错误（请确认后台已填写正确的商户私钥）');
        }
        openssl_sign($data, $sign, $privateKey, OPENSSL_ALGO_SHA256);
        return base64_encode($sign);
    }

    /** 平台公钥验签（SHA256WithRSA） */
    private function rsaPublicVerify($data, $sign)
    {
        $publicKey = $this->resolveKey($this->publicKey, ['PUBLIC KEY', 'RSA PUBLIC KEY']);
        if (!$publicKey) {
            throw new RuntimeException('验签失败：平台公钥错误（请确认后台已填写正确的平台公钥）');
        }
        $result = openssl_verify($data, base64_decode($sign), $publicKey, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    /** 依次尝试多种 PEM 包装格式解析密钥，返回 openssl key 资源或 null */
    private function resolveKey($key, $types)
    {
        $key = trim((string)$key);
        if ($key === '') {
            return null;
        }
        foreach ($types as $type) {
            $pem = self::wrapKey($key, $type);
            $res = @openssl_pkey_get_private($pem);
            if ($res === false) {
                $res = @openssl_pkey_get_public($pem);
            }
            if ($res !== false) {
                return $res;
            }
        }
        return null;
    }

    /** 将纯 base64 密钥包装为 PEM 格式（兼容已带 PEM 头的输入） */
    private static function wrapKey($key, $type)
    {
        $key = trim((string)$key);
        if (stripos($key, '-----BEGIN') === 0) {
            return $key;
        }
        $body = preg_replace('/\s+/', '', $key);
        return "-----BEGIN {$type}-----\n"
            . wordwrap($body, 64, "\n", true)
            . "\n-----END {$type}-----";
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
