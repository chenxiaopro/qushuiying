<?php
/**
 * 支付通道接口
 * 每个通道负责：将一笔平台订单转为支付请求（二维码/H5跳转/JSAPI参数），
 * 以及校验该通道上游的异步通知。
 */

interface ChannelInterface
{
    /** 通道编码 */
    public static function code();

    /** 通道显示名 */
    public static function label();

    /**
     * 发起支付
     * @param array $order 订单行
     * @param array $config 通道配置(JSON解析后数组)
     * @return array ['type' => 'qrcode'|'redirect'|'jsapi', 'data' => mixed, 'pay_trade_no' => string]
     */
    public function create($order, $config);

    /** 校验上游异步通知，成功返回平台订单号，失败返回 null */
    public function verifyNotify(array $config);
}
