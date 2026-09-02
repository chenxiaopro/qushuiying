<?php
/**
 * 平台后台 API
 * 所有写操作需要登录 + CSRF
 */

require_once __DIR__ . '/../../app/init.php';
require_once __DIR__ . '/../../app/ChannelFactory.php';

$action = (string)p_input('action', '');

try {
    switch ($action) {
        case 'login':
            p_rate_limit('admin_login', 10, 60);
            $username = (string)p_input('username', '');
            $password = (string)p_input('password', '');
            $admin = PDB::one('SELECT * FROM pay_admins WHERE username=?', [$username]);
            if (!$admin || !password_verify($password, $admin['password'])) {
                p_fail('用户名或密码错误');
            }
            session_regenerate_id(true);
            $_SESSION['pay_admin_id'] = (int)$admin['id'];
            p_ok(['csrf' => p_csrf_token()]);
            break;

        case 'logout':
            $_SESSION = [];
            session_destroy();
            p_ok();
            break;

        case 'me':
            $a = p_current_admin();
            if (!$a) {
                p_ok(['logged_in' => false]);
            }
            p_ok(['logged_in' => true, 'username' => $a['username'], 'csrf' => p_csrf_token()]);
            break;

        case 'stats':
            p_require_admin();
            p_ok([
                'merchant_count' => (int)PDB::scalar('SELECT COUNT(*) FROM pay_merchants'),
                'channel_count'  => (int)PDB::scalar('SELECT COUNT(*) FROM pay_channels'),
                'order_count'    => (int)PDB::scalar('SELECT COUNT(*) FROM pay_orders'),
                'paid_amount'    => (float)PDB::scalar('SELECT COALESCE(SUM(money),0) FROM pay_orders WHERE status=1'),
                'today_amount'   => (float)PDB::scalar('SELECT COALESCE(SUM(money),0) FROM pay_orders WHERE status=1 AND DATE(paid_at)=CURDATE()'),
            ]);
            break;

        case 'merchant_list':
            p_require_admin();
            p_ok(PDB::all('SELECT * FROM pay_merchants ORDER BY id DESC'));
            break;

        case 'merchant_save':
            p_require_admin();
            p_csrf_check();
            $id = (int)p_input('id', 0);
            $name = trim((string)p_input('name', ''));
            $status = (int)p_input('status', 1);
            if ($id > 0) {
                $exist = PDB::one('SELECT name FROM pay_merchants WHERE id=?', [$id]);
                if (!$exist) {
                    p_fail('商户不存在', 404);
                }
                $finalName = $name !== '' ? $name : $exist['name'];
                PDB::execute('UPDATE pay_merchants SET name=?, status=? WHERE id=?', [$finalName, $status, $id]);
                p_ok(['id' => $id]);
            }
            if ($name === '') {
                p_fail('商户名称不能为空');
            }
            $pid = p_gen_pid();
            $secret = bin2hex(random_bytes(16));
            PDB::execute('INSERT INTO pay_merchants(name,pid,secret,status) VALUES(?,?,?,1)', [$name, $pid, $secret]);
            p_ok(['id' => (int)PDB::lastId(), 'pid' => $pid, 'secret' => $secret]);
            break;

        case 'merchant_reset_key':
            p_require_admin();
            p_csrf_check();
            $id = (int)p_input('id', 0);
            $secret = bin2hex(random_bytes(16));
            PDB::execute('UPDATE pay_merchants SET secret=? WHERE id=?', [$secret, $id]);
            p_ok(['secret' => $secret]);
            break;

        case 'merchant_delete':
            p_require_admin();
            p_csrf_check();
            PDB::execute('DELETE FROM pay_merchants WHERE id=?', [(int)p_input('id', 0)]);
            p_ok();
            break;

        case 'channel_list':
            p_require_admin();
            $rows = PDB::all('SELECT * FROM pay_channels ORDER BY sort ASC, id ASC');
            foreach ($rows as &$r) {
                unset($r['config']);
            }
            p_ok($rows);
            break;

        case 'channel_get':
            p_require_admin();
            $ch = PDB::one('SELECT * FROM pay_channels WHERE id=?', [(int)p_input('id', 0)]);
            if (!$ch) {
                p_fail('通道不存在', 404);
            }
            p_ok($ch);
            break;

        case 'channel_save':
            p_require_admin();
            p_csrf_check();
            $id = (int)p_input('id', 0);
            $name = trim((string)p_input('name', ''));
            $code = trim((string)p_input('code', ''));
            $payType = trim((string)p_input('pay_type', ''));
            $configRaw = (string)p_input('config', '{}');
            if ($name === '' || !in_array($code, ChannelFactory::all(), true)) {
                p_fail('参数错误');
            }
            $cfg = json_decode($configRaw, true);
            if (!is_array($cfg)) {
                p_fail('通道配置不是合法 JSON');
            }
            $configJson = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($id > 0) {
                PDB::execute('UPDATE pay_channels SET name=?, code=?, pay_type=?, config=?, enabled=? WHERE id=?', [
                    $name, $code, $payType, $configJson, (int)p_input('enabled', 1), $id,
                ]);
                p_ok(['id' => $id]);
            }
            PDB::execute('INSERT INTO pay_channels(name,code,pay_type,config,enabled,sort) VALUES(?,?,?,?,?,?)', [
                $name, $code, $payType, $configJson, (int)p_input('enabled', 1), (int)p_input('sort', 0),
            ]);
            p_ok(['id' => (int)PDB::lastId()]);
            break;

        case 'channel_delete':
            p_require_admin();
            p_csrf_check();
            PDB::execute('DELETE FROM pay_channels WHERE id=?', [(int)p_input('id', 0)]);
            p_ok();
            break;

        case 'order_list':
            p_require_admin();
            $page = max(1, (int)p_input('page', 1));
            $size = 20;
            $where = 'WHERE 1=1';
            $args = [];
            if (($kw = trim((string)p_input('kw', ''))) !== '') {
                $where .= ' AND (trade_no LIKE ? OR out_trade_no LIKE ?)';
                $args[] = "%$kw%";
                $args[] = "%$kw%";
            }
            if (($status = (string)p_input('status', '')) !== '') {
                $where .= ' AND status=?';
                $args[] = (int)$status;
            }
            $total = (int)PDB::scalar("SELECT COUNT(*) FROM pay_orders $where", $args);
            $offset = ($page - 1) * $size;
            $rows = PDB::all("SELECT o.*, m.name AS merchant_name, c.name AS channel_name FROM pay_orders o LEFT JOIN pay_merchants m ON o.merchant_id=m.id LEFT JOIN pay_channels c ON o.channel_id=c.id $where ORDER BY o.id DESC LIMIT $size OFFSET $offset", $args);
            p_ok(['list' => $rows, 'total' => $total, 'page' => $page, 'size' => $size]);
            break;

        case 'channel_options':
            p_require_admin();
            p_ok(PDB::all('SELECT id,name,code,pay_type,enabled FROM pay_channels ORDER BY sort ASC'));
            break;

        default:
            p_fail('未知操作', 404);
    }
} catch (Exception $e) {
    p_fail_safe($e);
}
