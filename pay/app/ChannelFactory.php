<?php
/**
 * 支付通道工厂与调度
 */

require_once __DIR__ . '/channels/ChannelInterface.php';
require_once __DIR__ . '/channels/AlipayF2F.php';
require_once __DIR__ . '/channels/WechatV3Base.php';
require_once __DIR__ . '/channels/WechatNative.php';
require_once __DIR__ . '/channels/WechatH5.php';
require_once __DIR__ . '/channels/WechatJsapi.php';
require_once __DIR__ . '/channels/UpstreamEpay.php';

class ChannelFactory
{
    private static $map = [
        'alipay_f2f'    => 'AlipayF2F',
        'wechat_native' => 'WechatNative',
        'wechat_h5'     => 'WechatH5',
        'wechat_jsapi'  => 'WechatJsapi',
        'upstream_epay' => 'UpstreamEpay',
    ];

    public static function all()
    {
        return array_keys(self::$map);
    }

    public static function make($code)
    {
        if (!isset(self::$map[$code])) {
            throw new RuntimeException('未知通道：' . $code);
        }
        $cls = self::$map[$code];
        return new $cls();
    }

    public static function label($code)
    {
        try {
            $c = self::make($code);
            return $c::label();
        } catch (Exception $e) {
            return $code;
        }
    }

    /** 根据订单支付方式选择一个启用的通道 */
    public static function resolve($payType, $channelId = null)
    {
        if ($channelId) {
            $ch = PDB::one('SELECT * FROM pay_channels WHERE id=? AND enabled=1', [(int)$channelId]);
            if ($ch) {
                return $ch;
            }
        }
        $rows = PDB::all('SELECT * FROM pay_channels WHERE pay_type=? AND enabled=1 ORDER BY sort ASC, id ASC', [$payType]);
        if (empty($rows)) {
            throw new RuntimeException('暂无可用支付通道');
        }
        return $rows[0];
    }
}
