<?php
/**
 * 订单服务：下单、完成支付、异步通知商户
 */

require_once __DIR__ . '/ChannelFactory.php';
require_once __DIR__ . '/PaySign.php';

class OrderService
{
    /** 创建订单并返回支付结果（给 cashier 使用） */
    public static function createAndPay($merchantId, array $params)
    {
        $payType = (string)($params['type'] ?? 'alipay');
        $channelId = (int)($params['channel_id'] ?? 0);
        $channel = ChannelFactory::resolve($payType, $channelId ?: null);

        $tradeNo = p_gen_trade_no();
        PDB::execute(
            'INSERT INTO pay_orders(trade_no,out_trade_no,merchant_id,channel_id,pay_type,name,money,status,notify_url,return_url,created_at) VALUES(?,?,?,?,?,?,?,0,?,?,NOW())',
            [
                $tradeNo,
                (string)($params['out_trade_no'] ?? ''),
                $merchantId,
                (int)$channel['id'],
                $payType,
                (string)($params['name'] ?? ''),
                sprintf('%.2f', (float)($params['money'] ?? 0)),
                (string)($params['notify_url'] ?? ''),
                (string)($params['return_url'] ?? ''),
            ]
        );

        $order = PDB::one('SELECT * FROM pay_orders WHERE trade_no=?', [$tradeNo]);
        $config = $channel['config'] ? json_decode($channel['config'], true) : [];
        $config = is_array($config) ? $config : [];

        $notifyBase = p_site_url() . '/notify.php?channel=' . urlencode($channel['code']);
        $config['notify_url'] = $notifyBase;
        $config['return_url'] = p_site_url() . '/cashier.php?trade_no=' . urlencode($tradeNo);

        $ch = ChannelFactory::make($channel['code']);
        $extra = ['client_ip' => p_client_ip()];
        $result = $ch->create($order, $config, $extra);

        if (!empty($result['pay_trade_no'])) {
            PDB::execute('UPDATE pay_orders SET pay_trade_no=? WHERE trade_no=?', [$result['pay_trade_no'], $tradeNo]);
        }
        return [$order, $result, $channel];
    }

    /** 完成支付：状态流转 + 通知商户（幂等） */
    public static function markPaid($tradeNo, $payTradeNo = '')
    {
        $order = PDB::one('SELECT * FROM pay_orders WHERE trade_no=?', [$tradeNo]);
        if (!$order) {
            return false;
        }
        if ((int)$order['status'] === 1) {
            return true;
        }
        $changed = PDB::execute(
            'UPDATE pay_orders SET status=1, paid_at=NOW(), pay_trade_no=IF(?<>"",?,pay_trade_no) WHERE trade_no=? AND status=0',
            [$payTradeNo, $payTradeNo, $tradeNo]
        );
        if ($changed > 0) {
            self::notifyMerchant($tradeNo);
        }
        return true;
    }

    /** 异步通知商户（失败重试 3 次） */
    public static function notifyMerchant($tradeNo)
    {
        $order = PDB::one('SELECT * FROM pay_orders WHERE trade_no=?', [$tradeNo]);
        if (!$order || empty($order['notify_url'])) {
            return;
        }
        $merchant = PDB::one('SELECT * FROM pay_merchants WHERE id=?', [(int)$order['merchant_id']]);
        if (!$merchant) {
            return;
        }
        $params = [
            'pid'          => $merchant['pid'],
            'trade_no'     => $order['trade_no'],
            'out_trade_no' => $order['out_trade_no'],
            'type'         => $order['pay_type'],
            'name'         => $order['name'],
            'money'        => sprintf('%.2f', $order['money']),
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $params['sign'] = PaySign::make($params, $merchant['secret']);
        $params['sign_type'] = 'MD5';

        $url = $order['notify_url'];
        for ($i = 0; $i < 3; $i++) {
            try {
                $res = p_http_post($url, $params, [], 15);
                if (strtoupper(trim($res['body'])) === 'SUCCESS') {
                    return;
                }
            } catch (Exception $e) {
                error_log('[pay] notify merchant fail: ' . $e->getMessage());
            }
            sleep(2);
        }
    }
}
