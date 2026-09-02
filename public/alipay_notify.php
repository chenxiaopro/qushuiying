<?php
/**
 * 支付宝当面付异步通知入口（POST，支付宝服务器直接回调）
 * 验签成功后更新订单状态并给用户加点，返回 success
 */

require_once __DIR__ . '/../app/init.php';
require_once __DIR__ . '/../app/payment/AlipayF2F.php';

if (!AlipayF2F::enabled()) {
    exit('fail');
}

try {
    $alipay = new AlipayF2F(setting('alipay_app_id'), setting('alipay_private_key'), setting('alipay_public_key'));
    $info = $alipay->verifyNotify($_POST);
    if (!$info) {
        exit('fail');
    }

    $order = DB::one('SELECT * FROM orders WHERE order_sn=?', [$info['order_sn']]);
    if (!$order) {
        exit('fail');
    }
    if ((int)$order['status'] === 0) {
        DB::pdo()->beginTransaction();
        try {
            $locked = DB::one('SELECT * FROM orders WHERE id=? FOR UPDATE', [$order['id']]);
            if ((int)$locked['status'] === 0) {
                DB::execute('UPDATE orders SET status=1, trade_no=?, paid_at=NOW() WHERE id=?', [$info['trade_no'], $order['id']]);
                add_points($order['user_id'], (int)$order['points']);
            }
            DB::pdo()->commit();
        } catch (Throwable $e) {
            if (DB::pdo()->inTransaction()) {
                DB::pdo()->rollBack();
            }
            throw $e;
        }
    }
    exit('success');
} catch (Throwable $e) {
    error_log('[wm-alipay] ' . $e->getMessage());
    exit('fail');
}
