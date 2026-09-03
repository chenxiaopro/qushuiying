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

    /** 支付提交地址（api/pay/submit，POST 表单用） */
    public function submitAction()
    {
        return $this->api . '/api/pay/submit';
    }

    /** 生成支付提交参数（已含 pid/timestamp/sign/sign_type，供 POST 表单或 GET 链接使用） */
    public function submitParams($orderSn, $money, $type, $name)
    {
        $params = [
            'type'         => $type,
            'notify_url'   => $this->siteUrl() . '/pay_notify.php',
            'return_url'   => $this->siteUrl() . '/pay_return.php',
            'out_trade_no' => $orderSn,
            'name'         => $name,
            'money'        => sprintf('%.2f', $money),
        ];
        return $this->buildRequestParam($params);
    }

    /** 生成页面跳转支付地址（GET 链接方式，兼容旧用法） */
    public function submitUrl($orderSn, $money, $type, $name)
    {
        $params = $this->submitParams($orderSn, $money, $type, $name);
        return $this->submitAction() . '?' . http_build_query($params);
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

    /** 发起支付（API 接口，api/pay/create） */
    public function create($outTradeNo, $money, $type, $name, $param = [])
    {
        $params = [
            'type'         => $type,
            'notify_url'   => $this->siteUrl() . '/pay_notify.php',
            'return_url'   => $this->siteUrl() . '/pay_return.php',
            'out_trade_no' => $outTradeNo,
            'name'         => $name,
            'money'        => sprintf('%.2f', $money),
        ];
        foreach ($param as $k => $v) {
            $params[$k] = $v;
        }
        return $this->execute('api/pay/create', $params);
    }

    /** 订单退款（api/pay/refund） */
    public function refund($outRefundNo, $tradeNo, $money)
    {
        $params = [
            'trade_no'      => $tradeNo,
            'money'         => sprintf('%.2f', $money),
            'out_refund_no' => $outRefundNo,
        ];
        return $this->execute('api/pay/refund', $params);
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
        $privateKey = $this->resolvePrivateKey();
        if (!$privateKey) {
            $detail = '';
            while (($e = openssl_error_string()) !== false) {
                $detail = trim($e);
            }
            error_log('[wm-pay] 商户私钥解析失败: ' . $detail);
            throw new RuntimeException('签名失败：商户私钥错误（请确认后台已填写正确的商户私钥，不要包含多余空格或换行）');
        }
        openssl_sign($data, $sign, $privateKey, OPENSSL_ALGO_SHA256);
        return base64_encode($sign);
    }

    /** 平台公钥验签（SHA256WithRSA） */
    private function rsaPublicVerify($data, $sign)
    {
        $publicKey = $this->resolvePublicKey();
        if (!$publicKey) {
            throw new RuntimeException('验签失败：平台公钥错误（请确认后台已填写正确的平台公钥）');
        }
        $result = openssl_verify($data, base64_decode($sign), $publicKey, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    /** 解析商户私钥（依次尝试 PKCS#8 / PKCS#1） */
    private function resolvePrivateKey()
    {
        return self::parsePrivateKey($this->privateKey);
    }

    /** 解析平台公钥（依次尝试 PKCS#8 / PKCS#1） */
    private function resolvePublicKey()
    {
        return self::parsePublicKey($this->publicKey);
    }

    /** 解析商户私钥（静态，供自检使用） */
    public static function parsePrivateKey($key)
    {
        $key = trim((string)$key);
        if ($key === '') {
            return null;
        }
        foreach (['PRIVATE KEY', 'RSA PRIVATE KEY'] as $type) {
            $pem = self::wrapKey($key, $type);
            $res = @openssl_pkey_get_private($pem);
            if ($res !== false) {
                return $res;
            }
        }
        return null;
    }

    /** 解析平台公钥（静态，供自检使用） */
    public static function parsePublicKey($key)
    {
        $key = trim((string)$key);
        if ($key === '') {
            return null;
        }
        foreach (['PUBLIC KEY', 'RSA PUBLIC KEY'] as $type) {
            $pem = self::wrapKey($key, $type);
            $res = @openssl_pkey_get_public($pem);
            if ($res !== false) {
                return $res;
            }
        }
        return null;
    }

    /** 自检：解析密钥并做一次签名/验签往返，返回诊断信息 */
    public static function selfCheck()
    {
        $out = [
            'private_valid'   => false,
            'public_valid'    => false,
            'sign_ok'         => false,
            'derived_public'  => '',
            'message'         => '',
        ];

        $priv = self::parsePrivateKey(setting('epay_private_key', ''));
        if ($priv !== null) {
            $out['private_valid'] = true;
            $details = openssl_pkey_get_details($priv);
            $pubPem = $details['key'] ?? '';
            $pubBody = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $pubPem);
            $out['derived_public'] = $pubBody;
            $test = 'wm-epay-selfcheck-' . time();
            openssl_sign($test, $sig, $priv, OPENSSL_ALGO_SHA256);
            $pubDerived = openssl_pkey_get_public($pubPem);
            $out['sign_ok'] = $pubDerived !== false
                && openssl_verify($test, $sig, $pubDerived, OPENSSL_ALGO_SHA256) === 1;
        }

        if (self::parsePublicKey(setting('epay_public_key', '')) !== null) {
            $out['public_valid'] = true;
        }

        if (!$out['private_valid']) {
            $out['message'] = '商户私钥无法解析，请检查是否填写完整';
        } elseif (!$out['sign_ok']) {
            $out['message'] = '商户私钥自检失败';
        } else {
            $out['message'] = '商户私钥有效。若平台仍提示 RSA签名校验失败，请确认平台上登记的商户公钥与下方「商户公钥」一致。';
        }
        return $out;
    }

    /** 将任意形式的密钥输入包装为规范 PEM（兼容带/不带 PEM 头、单行或多行、含 BOM/隐藏字符） */
    private static function wrapKey($key, $type)
    {
        $key = trim((string)$key);
        if ($key === '') {
            return '';
        }
        // 剥离已有 PEM 头尾，只保留 base64 主体
        if (preg_match('/-----BEGIN [A-Z ]+-----/', $key)) {
            $key = preg_replace('/-----BEGIN [A-Z ]+-----|-----END [A-Z ]+-----/', '', $key);
        }
        // 去除所有非 base64 字符（空白、换行、BOM、隐藏字符等）
        $body = preg_replace('/[^A-Za-z0-9+\/=]/', '', $key);
        if ($body === '') {
            return '';
        }
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
