<?php
/**
 * 微信支付 V3 基础类（Native / H5 / JSAPI 共用）
 */

require_once __DIR__ . '/ChannelInterface.php';

abstract class WechatV3Base implements ChannelInterface
{
    const HOST = 'https://api.mch.weixin.qq.com';

    abstract protected function apiPath();

    abstract protected function buildBody($order, $config, $extra = []);

    abstract protected function resultType();

    public function create($order, $config, $extra = [])
    {
        $path = $this->apiPath();
        $body = $this->buildBody($order, $config, $extra);
        $method = 'POST';
        $url = self::HOST . $path;
        $authorization = $this->authorization($method, $path, $body, $config);
        $headers = [
            'Authorization: ' . $authorization,
            'Accept: application/json',
            'User-Agent: pay-platform/1.0',
        ];
        $res = p_http_post($url, $body, $headers, 20, true);
        $json = json_decode($res['body'], true);
        if (!is_array($json)) {
            throw new RuntimeException('微信接口响应异常');
        }
        if (isset($json['code']) || $res['status'] >= 400) {
            throw new RuntimeException('微信下单失败：' . ($json['message'] ?? ('HTTP ' . $res['status'])));
        }
        return $this->parseResult($json);
    }

    public function verifyNotify(array $config)
    {
        $raw = file_get_contents('php://input');
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $headers[str_replace('_', '-', substr($k, 5))] = $v;
            }
        }
        $serial = (string)($headers['WECHATPAY-SERIAL'] ?? '');
        $nonce = (string)($headers['WECHATPAY-NONCE'] ?? '');
        $timestamp = (string)($headers['WECHATPAY-TIMESTAMP'] ?? '');
        $signature = (string)($headers['WECHATPAY-SIGNATURE'] ?? '');
        if ($serial === '' || $signature === '') {
            return null;
        }

        $message = $timestamp . "\n" . $nonce . "\n" . $raw . "\n";
        $pub = $this->wechatPlatformPublicKey($serial, $config);
        if ($pub === null) {
            return null;
        }
        $ok = openssl_verify($message, base64_decode($signature), $pub, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            return null;
        }

        $decrypted = $this->decryptNotify($raw, (string)($config['api_v3_key'] ?? ''));
        $data = json_decode($decrypted, true);
        if (!is_array($data)) {
            return null;
        }
        if (($data['trade_state'] ?? '') !== 'SUCCESS') {
            return null;
        }
        return (string)($data['out_trade_no'] ?? '');
    }

    protected function authorization($method, $path, $body, $config)
    {
        $mchid = (string)($config['mchid'] ?? '');
        $serial = (string)($config['serial_no'] ?? '');
        $privateKey = (string)($config['apiclient_key'] ?? '');
        $nonce = bin2hex(random_bytes(16));
        $timestamp = (string)time();
        $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $message = $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $bodyJson . "\n";
        openssl_sign($message, $sig, $privateKey, OPENSSL_ALGO_SHA256);
        $signature = base64_encode($sig);
        return sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%s",serial_no="%s",signature="%s"',
            $mchid, $nonce, $timestamp, $serial, $signature
        );
    }

    protected function wechatPlatformPublicKey($serial, $config)
    {
        $dir = sys_get_temp_dir() . '/pay_wechat_keys';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . '/' . preg_replace('/[^A-Za-z0-9]/', '', $serial) . '.pem';
        if (is_file($file)) {
            return file_get_contents($file);
        }
        $url = self::HOST . '/v3/certificates';
        $authorization = $this->authorization('GET', '/v3/certificates', [], $config);
        $res = p_http_get($url, ['Authorization: ' . $authorization, 'Accept: application/json'], 15);
        $json = json_decode($res['body'], true);
        if (!is_array($json) || empty($json['data'])) {
            return null;
        }
        foreach ($json['data'] as $cert) {
            $cipher = $cert['encrypt_certificate'] ?? null;
            if (!$cipher) {
                continue;
            }
            $pem = $this->decryptCert($cipher, (string)($config['api_v3_key'] ?? ''));
            if ($pem !== null) {
                file_put_contents($file, $pem);
                return $pem;
            }
        }
        return null;
    }

    protected function decryptCert($cipher, $key)
    {
        $data = base64_decode($cipher['ciphertext']);
        $nonce = $cipher['nonce'];
        $aad = $cipher['associated_data'];
        return openssl_decrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $aad);
    }

    protected function decryptNotify($raw, $key)
    {
        $json = json_decode($raw, true);
        $resource = $json['resource'] ?? [];
        $data = base64_decode($resource['ciphertext']);
        $nonce = $resource['nonce'];
        $aad = $resource['associated_data'];
        return openssl_decrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $aad);
    }

    abstract protected function parseResult(array $json);
}
