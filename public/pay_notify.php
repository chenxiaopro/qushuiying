<?php
/**
 * 易支付异步通知入口（GET，平台直接回调，无自定义 query 参数）
 * 验签成功后更新订单状态并给用户加点，返回 success
 */

require_once __DIR__ . '/../app/init.php';
require_once __DIR__ . '/../app/payment/Epay.php';

if (!Epay::enabled()) {
    exit('fail');
}

try {
    $epay = new Epay(setting('epay_api'), setting('epay_pid'), setting('epay_public_key'), setting('epay_private_key'));
    $info = $epay->verifyNotify($_GET);
    if (!$info) {
        exit('fail');
    }
    if ($info['trade_status'] !== 'TRADE_SUCCESS') {
        exit('fail');
    }

    $order = DB::one('SELECT * FROM orders WHERE order_sn=?', [$info['order_sn']]);
    if (!$order) {
        exit('fail');
    }
    $paid = false;
    if ((int)$order['status'] === 0) {
        DB::pdo()->beginTransaction();
        try {
            $locked = DB::one('SELECT * FROM orders WHERE id=? FOR UPDATE', [$order['id']]);
            if ((int)$locked['status'] === 0) {
                DB::execute('UPDATE orders SET status=1, trade_no=?, paid_at=NOW() WHERE id=?', [$info['trade_no'], $order['id']]);
                add_points($order['user_id'], (int)$order['points']);
                $paid = true;
            }
            DB::pdo()->commit();
        } catch (Throwable $e) {
            if (DB::pdo()->inTransaction()) {
                DB::pdo()->rollBack();
            }
            throw $e;
        }
    }
    if ($paid) {
        $user = DB::one('SELECT username FROM users WHERE id=?', [$order['user_id']]);
        NotifyBark::recharge($user ? $user['username'] : ('#' . $order['user_id']), $order['amount'], $order['points']);
    }
    exit('success');
} catch (Throwable $e) {
    error_log('[wm-pay] ' . $e->getMessage());
    exit('fail');
}
