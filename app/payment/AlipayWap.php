<?php
/**
 * 支付宝官方手机网站支付（H5 / alipay.trade.wap.pay）对接
 *
 * 支付流程：
 *   1. 创建订单 -> buildForm() 生成支付宝收银台跳转表单
 *   2. 前端自动提交表单唤起支付宝收银台，用户付款
 *   3. 支付宝异步通知 alipay_notify.php -> 验签 -> 更新订单并给用户加点
 *
 * 后台设置需填写：支付宝 app_id、应用私钥(RSA2)、支付宝公钥 并开启开关。
 */

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../db.php';

class AlipayWap
{
    private $appId;
    private $privateKey;
    private $publicKey;
    private $gateway = 'https://openapi.alipay.com/gateway.do';
    private $signType = 'RSA2';

    public function __construct($appId, $privateKey, $publicKey)
    {
        $this->appId = trim((string)$appId);
        $this->privateKey = trim((string)$privateKey);
        $this->publicKey = trim((string)$publicKey);
    }

    public static function enabled()
    {
        return setting('alipay_enabled') == '1'
            && setting('alipay_app_id')
            && setting('alipay_private_key')
            && setting('alipay_public_key');
    }

    /** 手机网站支付：生成自动提交的跳转表单 HTML，前端提交后唤起支付宝收银台 */
    public function buildForm($outTradeNo, $amount, $subject, $returnUrl = '')
    {
        $biz = [
            'out_trade_no' => $outTradeNo,
            'total_amount' => sprintf('%.2f', $amount),
            'subject'       => $subject,
            'product_code'  => 'QUICK_WAP_WAY',
            'quit_url'      => $returnUrl ?: $this->siteUrl(),
        ];
        $params = $this->buildParams('alipay.trade.wap.pay', $biz, $returnUrl);
        $html = '<form id="alipayWapForm" name="alipayWapForm" action="' . htmlspecialchars($this->gateway) . '" method="POST">';
        foreach ($params as $k => $v) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($k, ENT_QUOTES) . '" value="' . htmlspecialchars($v, ENT_QUOTES) . '" />';
        }
        $html .= '<input type="submit" value="确认支付" style="display:none" />';
        $html .= '</form><script>document.getElementById("alipayWapForm").submit();</script>';
        return $html;
    }

    /** 查询订单交易状态，返回 trade_status 或 null */
    public function query($outTradeNo)
    {
        $biz = ['out_trade_no' => $outTradeNo];
        $params = $this->buildParams('alipay.trade.query', $biz);
        $response = http_post($this->gateway, $params, [], 20, false);
        if (!$this->verifySyncResponse($response, 'alipay.trade.query')) {
            error_log('[wm-alipay] query 验签失败：' . $response);
            return null;
        }
        $data = json_decode($response, true);
        $resp = $data['alipay_trade_query_response'] ?? null;
        if (!is_array($resp) || ($resp['code'] ?? '') !== '10000') {
            return null;
        }
        return $resp['trade_status'] ?? null;
    }

    /** 校验异步通知，成功返回 ['order_sn','money','trade_no','trade_status']，失败返回 null */
    public function verifyNotify($data)
    {
        if (empty($data['sign']) || !$this->verifyNotifySign($data)) {
            return null;
        }
        if (($data['app_id'] ?? '') !== $this->appId) {
            return null;
        }
        if (($data['trade_status'] ?? '') !== 'TRADE_SUCCESS' && ($data['trade_status'] ?? '') !== 'TRADE_FINISHED') {
            return null;
        }
        return [
            'order_sn'     => $data['out_trade_no'] ?? '',
            'money'        => $data['total_amount'] ?? '',
            'trade_no'     => $data['trade_no'] ?? '',
            'trade_status' => $data['trade_status'] ?? '',
        ];
    }

    /** 构造请求公共参数 + 签名 */
    private function buildParams($method, $biz, $returnUrl = '')
    {
        $params = [
            'app_id'      => $this->appId,
            'method'      => $method,
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => $this->signType,
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'notify_url'  => $this->siteUrl() . '/alipay_notify.php',
            'biz_content' => json_encode($biz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        if ($returnUrl !== '') {
            $params['return_url'] = $returnUrl;
        }
        // 请求签名时 sign_type 参与签名（支付宝请求签名规则），仅剔除 sign
        $params['sign'] = $this->rsaPrivateSign($this->getSignContent($params, false));
        return $params;
    }

    /** 同步响应验签：待验签字符串为响应 JSON 根节点（如 alipay_trade_query_response）的原始内容 */
    private function verifySyncResponse($responseContent, $method)
    {
        $rootNodeName = str_replace('.', '_', $method) . '_response';
        $obj = json_decode($responseContent);
        if (!is_object($obj) || empty($obj->sign)) {
            return false;
        }
        $sign = (string)$obj->sign;
        $rootIndex = strpos($responseContent, $rootNodeName);
        if ($rootIndex === false) {
            return false;
        }
        $start = $rootIndex + strlen($rootNodeName) + 2;
        $signIndex = strrpos($responseContent, '"sign"');
        if ($signIndex === false) {
            return false;
        }
        $end = $signIndex - 1;
        $signSource = substr($responseContent, $start, $end - $start);
        if ($signSource === false || trim($signSource) === '') {
            return false;
        }
        if ($this->rsaPublicVerify($signSource, $sign)) {
            return true;
        }
        if (strpos($signSource, '\\/') !== false) {
            $signSource = str_replace('\\/', '/', $signSource);
            return $this->rsaPublicVerify($signSource, $sign);
        }
        return false;
    }

    /** 异步通知验签：form 参数剔除 sign/sign_type，按 key 升序拼接，支付宝公钥 RSA2 */
    private function verifyNotifySign($data)
    {
        $sign = (string)($data['sign'] ?? '');
        if ($sign === '') {
            return false;
        }
        $content = $this->getSignContent($data);
        return $this->rsaPublicVerify($content, $sign);
    }

    /** 待签名字符串：按 key 升序 k=v&k=v 拼接，剔除 sign/空值/@开头；excludeSignType=true 时额外剔除 sign_type */
    private function getSignContent($params, $excludeSignType = true)
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($this->isEmpty($v) || strpos($v, '@') === 0 || $k === 'sign' || ($excludeSignType && $k === 'sign_type')) {
                continue;
            }
            $pairs[] = $k . '=' . $v;
        }
        return implode('&', $pairs);
    }

    private function isEmpty($value)
    {
        return $value === null || trim((string)$value) === '';
    }

    /** 应用私钥签名（RSA2，输出 base64） */
    private function rsaPrivateSign($data)
    {
        $privateKey = $this->resolveKey($this->privateKey, ['PRIVATE KEY', 'RSA PRIVATE KEY']);
        if (!$privateKey) {
            throw new RuntimeException('签名失败：应用私钥错误（请确认后台已填写正确的应用私钥）');
        }
        openssl_sign($data, $sign, $privateKey, OPENSSL_ALGO_SHA256);
        return base64_encode($sign);
    }

    /** 支付宝公钥验签（RSA2） */
    private function rsaPublicVerify($data, $sign)
    {
        $publicKey = $this->resolveKey($this->publicKey, ['PUBLIC KEY', 'RSA PUBLIC KEY']);
        if (!$publicKey) {
            throw new RuntimeException('验签失败：支付宝公钥错误（请确认后台已填写正确的支付宝公钥）');
        }
        $result = openssl_verify($data, base64_decode($sign), $publicKey, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    /** 依次尝试多种 PEM 包装格式解析密钥 */
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
