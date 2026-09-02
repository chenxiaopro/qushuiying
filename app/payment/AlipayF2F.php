<?php
/**
 * 支付宝当面付（F2F / alipay.trade.precreate）对接
 *
 * 基于支付宝官方 PHP SDK（AopClient）实现，流程：
 *   1. 创建订单 -> precreate() 预下单，返回支付宝收款二维码内容 qr_code
 *   2. 前端展示二维码，用户用支付宝扫码付款
 *   3. 支付宝异步通知 alipay_notify.php -> 验签 -> 更新订单并给用户加点
 *
 * 后台设置需填写：支付宝 app_id、应用私钥(RSA2)、支付宝公钥 并开启开关。
 */

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/alipay/AopClient.php';
require_once __DIR__ . '/alipay/request/AlipayTradePrecreateRequest.php';
require_once __DIR__ . '/alipay/request/AlipayTradeQueryRequest.php';

class AlipayF2F
{
    private $aop;
    private $appId;
    private $signType = 'RSA2';
    private $gateway = 'https://openapi.alipay.com/gateway.do';

    public function __construct($appId, $privateKey, $publicKey)
    {
        $this->appId = trim((string)$appId);

        $this->aop = new AopClient();
        $this->aop->gatewayUrl = $this->gateway;
        $this->aop->appId = $this->appId;
        $this->aop->signType = $this->signType;
        $this->aop->rsaPrivateKey = trim((string)$privateKey);
        $this->aop->alipayrsaPublicKey = trim((string)$publicKey);
        $this->aop->apiVersion = '1.0';
        $this->aop->postCharset = 'UTF-8';
        $this->aop->format = 'json';
    }

    public static function enabled()
    {
        return setting('alipay_enabled') == '1'
            && setting('alipay_app_id')
            && setting('alipay_private_key')
            && setting('alipay_public_key');
    }

    /** 当面付预下单，成功返回二维码内容 qr_code，失败抛出 RuntimeException */
    public function precreate($outTradeNo, $amount, $subject)
    {
        $biz = [
            'out_trade_no' => $outTradeNo,
            'total_amount' => sprintf('%.2f', $amount),
            'subject'       => $subject,
        ];
        $request = new AlipayTradePrecreateRequest();
        $request->setBizContent(json_encode($biz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $request->setNotifyUrl($this->notifyUrl());

        try {
            $resp = $this->aop->execute($request);
        } catch (\Throwable $e) {
            throw new \RuntimeException('支付宝网关通信异常：' . $e->getMessage());
        }
        if ($resp === false) {
            throw new \RuntimeException('支付宝网关请求失败，请稍后重试');
        }

        $respData = isset($resp->alipay_trade_precreate_response) ? $resp->alipay_trade_precreate_response : null;
        if (!$respData) {
            throw new \RuntimeException('支付宝返回异常，请稍后重试');
        }
        if ((string)$respData->code !== '10000') {
            $msg = !empty($respData->sub_msg) ? $respData->sub_msg : (!empty($respData->msg) ? $respData->msg : '未知错误');
            throw new \RuntimeException('支付宝下单失败：' . $msg);
        }
        return (string)$respData->qr_code;
    }

    /** 主动查询订单交易状态，返回 trade_status，失败返回 null */
    public function query($outTradeNo)
    {
        $biz = ['out_trade_no' => $outTradeNo];
        $request = new AlipayTradeQueryRequest();
        $request->setBizContent(json_encode($biz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            $resp = $this->aop->execute($request);
        } catch (\Throwable $e) {
            error_log('[wm-alipay] query 异常：' . $e->getMessage());
            return null;
        }
        if ($resp === false) {
            return null;
        }
        $respData = isset($resp->alipay_trade_query_response) ? $resp->alipay_trade_query_response : null;
        if (!$respData || (string)$respData->code !== '10000') {
            return null;
        }
        return isset($respData->trade_status) ? (string)$respData->trade_status : null;
    }

    /** 校验异步通知，成功返回 ['order_sn','money','trade_no','trade_status']，失败返回 null */
    public function verifyNotify($data)
    {
        if (empty($data['sign'])) {
            return null;
        }
        $signType = !empty($data['sign_type']) ? (string)$data['sign_type'] : $this->signType;
        try {
            $ok = $this->aop->rsaCheckV2($data, null, $signType);
        } catch (\Throwable $e) {
            error_log('[wm-alipay] 通知验签异常：' . $e->getMessage());
            return null;
        }
        if (!$ok) {
            return null;
        }
        if (($data['app_id'] ?? '') !== $this->appId) {
            return null;
        }
        $tradeStatus = (string)($data['trade_status'] ?? '');
        if ($tradeStatus !== 'TRADE_SUCCESS' && $tradeStatus !== 'TRADE_FINISHED') {
            return null;
        }
        return [
            'order_sn'     => (string)($data['out_trade_no'] ?? ''),
            'money'        => (string)($data['total_amount'] ?? ''),
            'trade_no'     => (string)($data['trade_no'] ?? ''),
            'trade_status' => $tradeStatus,
        ];
    }

    private function notifyUrl()
    {
        return $this->siteUrl() . '/alipay_notify.php';
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
