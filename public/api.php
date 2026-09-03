<?php
/**
 * 前台 API 统一入口
 * 接口列表：
 *   POST register      注册
 *   POST login         登录
 *   POST logout        退出
 *   GET  me            当前用户信息
 *   POST parse         解析去水印（消耗点数）
 *   POST pay_create    创建充值订单（易支付/支付宝当面付）
 *   POST pay_query     查询订单支付状态
 *   POST recharge_card 卡密充值
 */

require_once __DIR__ . '/../app/init.php';
require_once __DIR__ . '/../app/ParserFactory.php';
require_once __DIR__ . '/../app/payment/Epay.php';
require_once __DIR__ . '/../app/payment/AlipayF2F.php';

try {
    $action = input('action', '');
    $csrfSkip = ['me', 'parse_types'];
    $needCsrf = !in_array($action, $csrfSkip, true)
        && $_SERVER['REQUEST_METHOD'] !== 'GET';
    if ($needCsrf) {
        csrf_check();
    }
    switch ($action) {
        case 'csrf':
            ok(['csrf' => csrf_token()]);
            break;
        case 'register':
            api_register();
            break;
        case 'login':
            api_login();
            break;
        case 'logout':
            api_logout();
            break;
        case 'me':
            api_me();
            break;
        case 'parse_types':
            api_parse_types();
            break;
        case 'parse':
            api_parse();
            break;
        case 'pay_create':
            api_pay_create();
            break;
        case 'pay_query':
            api_pay_query();
            break;
        case 'recharge_card':
            api_recharge_card();
            break;
        default:
            fail('未知操作', 404);
    }
} catch (Throwable $e) {
    fail_safe($e);
}

function api_register()
{
    rate_limit_ip('register', 8, 600);
    $username = trim(input('username', ''));
    $password = (string)input('password', '');

    if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]{2,20}$/u', $username)) {
        fail('用户名需为 2-20 位字母、数字、下划线或中文');
    }
    if (mb_strlen($password) < 6 || mb_strlen($password) > 32) {
        fail('密码长度需为 6-32 位');
    }
    if (DB::one('SELECT id FROM users WHERE username=?', [$username])) {
        fail('用户名已存在');
    }

    $registerPoints = max(0, (int)setting('register_points', 0));
    DB::execute('INSERT INTO users(username,password,points,total_points,status) VALUES(?,?,?,?,1)', [
        $username, password_hash($password, PASSWORD_DEFAULT), $registerPoints, $registerPoints,
    ]);
    NotifyBark::register($username);
    ok(['username' => $username, 'register_points' => $registerPoints, 'msg' => '注册成功']);
}

function api_login()
{
    rate_limit_ip('login', 12, 300);
    $username = trim(input('username', ''));
    $password = (string)input('password', '');
    $remember = input('remember', '0') === '1';

    $u = DB::one('SELECT * FROM users WHERE username=?', [$username]);
    if (!$u || !password_verify($password, $u['password'])) {
        fail('用户名或密码错误');
    }
    if ((int)$u['status'] !== 1) {
        fail('账号已被禁用');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];

    // 勾选"30天免登录"：生成令牌存库，写入长效 Cookie
    if ($remember) {
        $token = bin2hex(random_bytes(32));
        DB::execute('UPDATE users SET remember_token=? WHERE id=?', [hash('sha256', $token), $u['id']]);
        setcookie('wm_remember', $u['id'] . ':' . $token, [
            'expires'  => time() + 30 * 24 * 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    }

    DB::execute('UPDATE users SET last_login_at=NOW(), last_login_ip=? WHERE id=?', [client_ip(), $u['id']]);
    ok(['id' => (int)$u['id'], 'username' => $u['username'], 'points' => (int)$u['points']]);
}

function api_logout()
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id > 0) {
        DB::execute('UPDATE users SET remember_token=NULL WHERE id=?', [$id]);
    }
    unset($_SESSION['user_id']);
    setcookie('wm_remember', '', time() - 3600, '/');
    ok();
}

function api_me()
{
    $u = current_user();
    $data = [
        'logged_in' => false,
        'csrf'      => csrf_token(),
        'cost'      => (int)setting('parse_cost', 1),
    ];
    if ($u && (int)$u['status'] === 1) {
        $data['logged_in'] = true;
        $data['id'] = (int)$u['id'];
        $data['username'] = $u['username'];
        $data['points'] = (int)$u['points'];
    } elseif ($u && (int)$u['status'] !== 1) {
        unset($_SESSION['user_id']);
    }
    ok($data);
}

function api_parse_types()
{
    $rows = DB::all('SELECT id,name,`key` FROM parse_types WHERE enabled=1 ORDER BY sort ASC, id ASC');
    ok(['list' => $rows]);
}

function api_parse()
{
    $u = require_login();
    check_parse_rate_limit($u['id']);

    $text = trim((string)input('text', ''));
    if (mb_strlen($text) > 5000) {
        fail('分享内容过长');
    }

    $cost = max(0, (int)setting('parse_cost', 1));
    if ($cost > 0 && (int)$u['points'] < $cost) {
        fail('点数不足，请先充值', 402);
    }

    $platform = ParserFactory::detect($text);
    $mode = trim((string)input('mode', ''));
    try {
        $result = ParserFactory::parse($text, $mode !== '' ? $mode : null);
    } catch (RuntimeException $e) {
        DB::execute('INSERT INTO parse_logs(user_id,text,platform,cost,ip,created_at) VALUES(?,?,?,?,?,NOW())', [
            $u['id'], $text, $platform, 0, client_ip(),
        ]);
        fail($e->getMessage(), 1);
    }

    // 解析成功后再扣点（扣点与写日志在同一事务内）
    DB::pdo()->beginTransaction();
    try {
        if (!deduct_points($u['id'], $cost)) {
            DB::pdo()->rollBack();
            fail('点数不足，请先充值', 402);
        }
        DB::execute('INSERT INTO parse_logs(user_id,text,platform,title,cover,video_url,cost,ip,created_at) VALUES(?,?,?,?,?,?,?,?,NOW())', [
            $u['id'], $text, $result['platform'], $result['title'] ?? '', $result['cover'] ?? '',
            $result['video_url'] ?? '', $cost, client_ip(),
        ]);
        DB::pdo()->commit();
    } catch (Throwable $e) {
        if (DB::pdo()->inTransaction()) {
            DB::pdo()->rollBack();
        }
        throw $e;
    }

    if (!is_array($result['music'] ?? null)) {
        $mu = (string)($result['music'] ?? '');
        $result['music'] = ['title' => '', 'author' => '', 'cover' => '', 'url' => $mu];
    }
    $left = (int)DB::scalar('SELECT points FROM users WHERE id=?', [$u['id']]);
    ok([
        'result' => [
            'platform'     => $result['platform'],
            'platform_name' => platform_name($result['platform']),
            'title'        => $result['title'] ?? '',
            'desc'         => $result['desc'] ?? '',
            'cover'        => $result['cover'] ?? '',
            'video_url'    => $result['video_url'] ?? '',
            'music'        => $result['music'] ?? [],
            'images'       => $result['images'] ?? [],
            'video_backup' => $result['video_backup'] ?? [],
            'live'         => $result['live'] ?? [],
            'type'         => (int)($result['type'] ?? 1),
            'author'       => $result['author'] ?? null,
            'source'       => $result['source'] ?? '',
            'list'         => $result['list'] ?? [],
        ],
        'cost'  => $cost,
        'points_left' => $left,
    ]);
}

function api_pay_create()
{
    $u = require_login();
    cleanup_expired_orders();

    $type = input('type', 'alipay');
    $subject = setting('site_name', '点数充值');

    $points = max(1, min(100000, (int)input('points', 0)));
    $perYuan = max(1, (int)setting('points_per_yuan', 10));
    $money = round($points / $perYuan, 2);
    if ($money < 0.01) {
        fail('充值金额过低');
    }
    $orderSn = gen_order_sn();

    // 支付宝当面付通道
    if ($type === 'alipay_f2f') {
        if (!AlipayF2F::enabled()) {
            fail('支付宝支付通道未开启，请联系站长充值');
        }
        DB::execute('INSERT INTO orders(order_sn,user_id,amount,points,status,pay_type,created_at) VALUES(?,?,?,?,0,?,NOW())', [
            $orderSn, $u['id'], $money, $points, $type,
        ]);
        try {
            $alipay = new AlipayF2F(setting('alipay_app_id'), setting('alipay_private_key'), setting('alipay_public_key'));
            $qrCode = $alipay->precreate($orderSn, $money, $subject . '-' . $points . '点');
        } catch (RuntimeException $e) {
            fail('支付配置错误：' . $e->getMessage());
        }
        ok(['order_sn' => $orderSn, 'qr_code' => $qrCode, 'money' => $money, 'points' => $points, 'type' => 'alipay_f2f']);
    }

    // 易支付通道
    if (!Epay::enabled()) {
        fail('支付通道未开启，请联系站长充值');
    }
    if (!in_array($type, array_keys(Epay::payTypes()), true)) {
        fail('不支持的支付方式');
    }

    DB::execute('INSERT INTO orders(order_sn,user_id,amount,points,status,pay_type,created_at) VALUES(?,?,?,?,0,?,NOW())', [
        $orderSn, $u['id'], $money, $points, $type,
    ]);

    try {
        $epay = new Epay(setting('epay_api'), setting('epay_pid'), setting('epay_public_key'), setting('epay_private_key'));
        $url = $epay->submitUrl($orderSn, $money, $type, $subject . '-' . $points . '点');
    } catch (RuntimeException $e) {
        fail('支付配置错误：' . $e->getMessage());
    }
    ok(['order_sn' => $orderSn, 'pay_url' => $url, 'money' => $money, 'points' => $points, 'type' => $type]);
}

function api_pay_query()
{
    $u = require_login();
    $orderSn = trim((string)input('order_sn', ''));
    if ($orderSn === '') {
        fail('缺少订单号');
    }
    $order = DB::one('SELECT id,status,points FROM orders WHERE order_sn=? AND user_id=?', [$orderSn, $u['id']]);
    if (!$order) {
        fail('订单不存在');
    }
    ok(['status' => (int)$order['status'], 'points' => (int)$order['points']]);
}

function api_recharge_card()
{
    $u = require_login();
    cleanup_expired_cards();
    $cardNo = strtoupper(trim((string)input('card', '')));
    if (!preg_match('/^[A-Z0-9]{8,32}$/', $cardNo)) {
        fail('卡密格式不正确');
    }

    DB::pdo()->beginTransaction();
    try {
        $card = DB::one('SELECT * FROM cards WHERE card_no=? FOR UPDATE', [$cardNo]);
        if (!$card || (int)$card['status'] === 1) {
            DB::pdo()->rollBack();
            fail('卡密不存在或已被使用');
        }
        DB::execute('UPDATE cards SET status=1, used_by=?, used_at=NOW() WHERE id=?', [$u['id'], $card['id']]);
        add_points($u['id'], (int)$card['points']);
        $left = (int)DB::scalar('SELECT points FROM users WHERE id=?', [$u['id']]);
        DB::pdo()->commit();
        ok(['points' => (int)$card['points'], 'points_left' => $left]);
    } catch (Throwable $e) {
        if (DB::pdo()->inTransaction()) {
            DB::pdo()->rollBack();
        }
        throw $e;
    }
}

