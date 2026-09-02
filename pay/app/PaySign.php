<?php
/**
 * 彩虹易支付标准签名
 *
 * 提交签名：md5( 参数按 key 升序拼接 k=v&k=v... 后附 &key=商户密钥 )
 * 通知验签、查询接口沿用同一规则，sign 与 sign_type 不参与签名。
 */

class PaySign
{
    /** 生成 MD5 签名（大写） */
    public static function make(array $params, $key)
    {
        $params = array_filter($params, function ($k) {
            return $k !== 'sign' && $k !== 'sign_type';
        }, ARRAY_FILTER_USE_KEY);
        $params['key'] = $key;
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $pairs[] = $k . '=' . $v;
        }
        return strtoupper(md5(implode('&', $pairs)));
    }

    /** 校验签名 */
    public static function verify(array $params, $key)
    {
        $sign = (string)($params['sign'] ?? '');
        if ($sign === '') {
            return false;
        }
        return hash_equals(self::make($params, $key), strtoupper($sign));
    }
}
