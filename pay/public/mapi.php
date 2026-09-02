<?php
/**
 * 订单查询接口（彩虹易支付协议 mapi.php）
 *
 * 参数：pid, trade_no, out_trade_no, sign, sign_type
 * 返回 JSON: code=1 成功, trade_no, out_trade_no, type, name, money, trade_status
 */

require_once __DIR__ . '/../app/init.php';
require_once __DIR__ . '/../app/PaySign.php';

try {
    $pid = (string)p_input('pid');
    $merchant = PDB::one('SELECT * FROM pay_merchants WHERE pid=?', [$pid]);
    if (!$merchant || (int)$merchant['status'] !== 1) {
        p_fail('商户不存在或已被禁用');
    }
    $params = $_GET + $_POST;
    if (!PaySign::verify($params, $merchant['secret'])) {
        p_fail('签名错误');
    }

    $tradeNo = (string)($params['trade_no'] ?? '');
    $outTradeNo = (string)($params['out_trade_no'] ?? '');
    if ($tradeNo !== '') {
        $order = PDB::one('SELECT * FROM pay_orders WHERE trade_no=? AND merchant_id=?', [$tradeNo, (int)$merchant['id']]);
    } elseif ($outTradeNo !== '') {
        $order = PDB::one('SELECT * FROM pay_orders WHERE out_trade_no=? AND merchant_id=? ORDER BY id DESC LIMIT 1', [$outTradeNo, (int)$merchant['id']]);
    } else {
        p_fail('缺少订单号');
    }

    if (!$order) {
        p_json_out(['code' => 0, 'msg' => '订单不存在']);
    }

    $statusMap = [0 => 'NOTPAY', 1 => 'TRADE_SUCCESS', 2 => 'CLOSED'];
    p_json_out([
        'code'         => 1,
        'msg'          => '查询成功',
        'trade_no'     => $order['trade_no'],
        'out_trade_no' => $order['out_trade_no'],
        'type'         => $order['pay_type'],
        'name'         => $order['name'],
        'money'        => sprintf('%.2f', $order['money']),
        'trade_status' => $statusMap[(int)$order['status']] ?? 'NOTPAY',
    ]);
} catch (Exception $e) {
    p_fail_safe($e, '查询失败');
}
