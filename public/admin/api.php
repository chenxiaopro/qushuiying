<?php
/**
 * 后台 API
 * 接口列表：
 *   POST login              管理员登录
 *   POST logout             退出
 *   GET  stats              仪表盘统计
 *   GET  users?page=&q=     用户列表
 *   POST user_action        用户操作(加点/扣点/禁用/重置密码)
 *   GET  orders?page=&status= 订单列表
 *   GET  logs?page=&q=      解析记录
 *   GET  cards?page=        卡密列表
 *   POST gen_cards          批量生成卡密
 *   GET  settings           读取设置
 *   POST save_settings      保存设置
 */

require_once __DIR__ . '/../../app/init.php';
require_once __DIR__ . '/../../app/payment/Epay.php';

try {
    $action = input('action', '');
    if ($action !== 'login' && $action !== '' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
        if ($action !== 'logout') {
            csrf_check();
        }
    }
    switch ($action) {
        case 'login':
            admin_login();
            break;
        case 'logout':
            unset($_SESSION['admin_id']);
            ok();
            break;
        case 'stats':
            require_admin();
            admin_stats();
            break;
        case 'users':
            require_admin();
            admin_users();
            break;
        case 'user_action':
            require_admin();
            admin_user_action();
            break;
        case 'orders':
            require_admin();
            admin_orders();
            break;
        case 'logs':
            require_admin();
            admin_logs();
            break;
        case 'cards':
            require_admin();
            admin_cards();
            break;
        case 'gen_cards':
            require_admin();
            admin_gen_cards();
            break;
        case 'cards_export':
            require_admin();
            admin_cards_export();
            break;
        case 'settings':
            require_admin();
            admin_settings_get();
            break;
        case 'save_settings':
            require_admin();
            admin_settings_save();
            break;
        case 'epay_check':
            require_admin();
            ok(Epay::selfCheck());
            break;
        case 'apis':
            require_admin();
            admin_apis();
            break;
        case 'api_save':
            require_admin();
            admin_api_save();
            break;
        case 'api_delete':
            require_admin();
            admin_api_delete();
            break;
        case 'api_toggle':
            require_admin();
            admin_api_toggle();
            break;
        case 'parse_types':
            require_admin();
            admin_parse_types();
            break;
        case 'parse_type_save':
            require_admin();
            admin_parse_type_save();
            break;
        case 'parse_type_delete':
            require_admin();
            admin_parse_type_delete();
            break;
        case 'parse_type_toggle':
            require_admin();
            admin_parse_type_toggle();
            break;
        case 'versions':
            require_admin();
            admin_versions();
            break;
        case 'version_save':
            require_admin();
            admin_version_save();
            break;
        case 'version_delete':
            require_admin();
            admin_version_delete();
            break;
        case 'me':
            $a = current_admin();
            if (!$a) {
                fail('请先登录', 401);
            }
            ok(['username' => $a['username'], 'csrf' => csrf_token()]);
            break;
        default:
            fail('未知操作', 404);
    }
} catch (Throwable $e) {
    fail_safe($e);
}

function admin_login()
{
    rate_limit_ip('admin_login', 8, 300);
    $username = trim(input('username', ''));
    $password = (string)input('password', '');
    $a = DB::one('SELECT * FROM admins WHERE username=?', [$username]);
    if (!$a || !password_verify($password, $a['password'])) {
        fail('用户名或密码错误');
    }
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$a['id'];
    ok(['username' => $a['username'], 'csrf' => csrf_token()]);
}

function admin_stats()
{
    $today = date('Y-m-d');
    $recent = DB::all('SELECT p.id, u.username, p.platform, p.title, p.cost, p.created_at FROM parse_logs p LEFT JOIN users u ON u.id=p.user_id ORDER BY p.id DESC LIMIT 8');
    ok([
        'users'       => (int)DB::scalar('SELECT COUNT(*) FROM users'),
        'users_today' => (int)DB::scalar('SELECT COUNT(*) FROM users WHERE DATE(created_at)=?', [$today]),
        'orders'      => (int)DB::scalar('SELECT COUNT(*) FROM orders WHERE status=1'),
        'income'      => (float)DB::scalar('SELECT IFNULL(SUM(amount),0) FROM orders WHERE status=1'),
        'parses'      => (int)DB::scalar('SELECT COUNT(*) FROM parse_logs'),
        'parses_today'=> (int)DB::scalar('SELECT COUNT(*) FROM parse_logs WHERE DATE(created_at)=?', [$today]),
        'points_total'=> (int)DB::scalar('SELECT IFNULL(SUM(total_points),0) FROM users'),
        'cards_unused'=> (int)DB::scalar('SELECT COUNT(*) FROM cards WHERE status=0'),
        'recent_parses' => $recent,
    ]);
}

function admin_users()
{
    $page = max(1, (int)input('page', 1));
    $q = trim(input('q', ''));
    $where = '';
    $params = [];
    if ($q !== '') {
        $where = 'WHERE username LIKE ?';
        $params[] = '%' . $q . '%';
    }
    $total = (int)DB::scalar('SELECT COUNT(*) FROM users ' . $where, $params);
    $pageSize = 20;
    $pages = max(1, ceil($total / $pageSize));
    $offset = ($page - 1) * $pageSize;
    $rows = DB::all(
        'SELECT id,username,points,total_points,status,last_login_at,last_login_ip,created_at FROM users ' . $where .
        ' ORDER BY id DESC LIMIT ' . (int)$offset . ',' . (int)$pageSize,
        $params
    );
    ok(['list' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages]);
}

function admin_user_action()
{
    $id = (int)input('id', 0);
    $action = input('sub', '');
    $u = DB::one('SELECT * FROM users WHERE id=?', [$id]);
    if (!$u) {
        fail('用户不存在');
    }

    switch ($action) {
        case 'add_points':
            $p = (int)input('points', 0);
            if ($p <= 0 || $p > 1000000) {
                fail('点数需在 1-1000000 之间');
            }
            add_points($id, $p);
            ok(['points' => (int)$u['points'] + $p]);
            break;
        case 'deduct_points':
            $p = (int)input('points', 0);
            if ($p <= 0 || $p > 1000000) {
                fail('点数需在 1-1000000 之间');
            }
            if (!deduct_points($id, $p)) {
                fail('用户余额不足');
            }
            ok();
            break;
        case 'toggle':
            $new = (int)$u['status'] === 1 ? 0 : 1;
            DB::execute('UPDATE users SET status=? WHERE id=?', [$new, $id]);
            ok(['status' => $new]);
            break;
        case 'reset_pwd':
            $pwd = (string)input('password', '');
            if (mb_strlen($pwd) < 6 || mb_strlen($pwd) > 32) {
                fail('新密码长度需为 6-32 位');
            }
            DB::execute('UPDATE users SET password=? WHERE id=?', [password_hash($pwd, PASSWORD_DEFAULT), $id]);
            ok();
            break;
        default:
            fail('未知操作');
    }
}

function admin_orders()
{
    cleanup_expired_orders();
    $page = max(1, (int)input('page', 1));
    $status = input('status', '');
    $where = '';
    $params = [];
    if ($status !== '' && in_array((int)$status, [0, 1, 2], true)) {
        $where = 'WHERE o.status=' . (int)$status;
    }
    $total = (int)DB::scalar('SELECT COUNT(*) FROM orders o ' . $where, $params);
    $pageSize = 20;
    $pages = max(1, ceil($total / $pageSize));
    $offset = ($page - 1) * $pageSize;
    $rows = DB::all(
        'SELECT o.*, u.username FROM orders o LEFT JOIN users u ON u.id=o.user_id ' . $where .
        ' ORDER BY o.id DESC LIMIT ' . (int)$offset . ',' . (int)$pageSize,
        $params
    );
    ok(['list' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages]);
}

function admin_logs()
{
    $page = max(1, (int)input('page', 1));
    $total = (int)DB::scalar('SELECT COUNT(*) FROM parse_logs');
    $pageSize = 20;
    $pages = max(1, ceil($total / $pageSize));
    $offset = ($page - 1) * $pageSize;
    $rows = DB::all(
        'SELECT p.*, u.username FROM parse_logs p LEFT JOIN users u ON u.id=p.user_id' .
        ' ORDER BY p.id DESC LIMIT ' . (int)$offset . ',' . (int)$pageSize
    );
    ok(['list' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages]);
}

function admin_cards()
{
    cleanup_expired_cards();
    $page = max(1, (int)input('page', 1));
    $total = (int)DB::scalar('SELECT COUNT(*) FROM cards');
    $pageSize = 20;
    $pages = max(1, ceil($total / $pageSize));
    $offset = ($page - 1) * $pageSize;
    $rows = DB::all(
        'SELECT c.*, u.username FROM cards c LEFT JOIN users u ON u.id=c.used_by' .
        ' ORDER BY c.id DESC LIMIT ' . (int)$offset . ',' . (int)$pageSize
    );
    ok(['list' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages]);
}

function admin_gen_cards()
{
    cleanup_expired_cards();
    $count = max(1, min(500, (int)input('count', 10)));
    $points = max(1, (int)input('points', 10));
    $cards = [];
    for ($i = 0; $i < $count; $i++) {
        $no = gen_card();
        DB::execute('INSERT INTO cards(card_no,points,status,created_at) VALUES(?,?,0,NOW())', [$no, $points]);
        $cards[] = $no;
    }
    ok(['cards' => $cards, 'count' => $count, 'points' => $points]);
}

function admin_cards_export()
{
    cleanup_expired_cards();
    $rows = DB::all('SELECT card_no,points FROM cards WHERE status=0 ORDER BY id ASC');
    ok(['cards' => $rows, 'count' => count($rows)]);
}

function admin_settings_get()
{
    $keys = ['site_name', 'site_desc', 'announcement', 'parse_cost', 'register_points',
             'points_per_yuan', 'epay_enabled', 'epay_api', 'epay_pid', 'epay_public_key', 'epay_private_key', 'epay_pay_types',
             'alipay_enabled', 'alipay_app_id', 'alipay_private_key', 'alipay_public_key',
               'wechat_enabled', 'wechat_name', 'wechat_qrcode', 'wechat_desc', 'site_version',
               'bark_enabled', 'bark_server', 'bark_key', 'bark_sound', 'bark_notify_register', 'bark_notify_recharge',
               'share_title', 'share_desc', 'share_image'];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = setting($k);
    }
    // 开关类字段默认关闭，避免旧库无此 key 时下拉未选中导致保存校验失败
    foreach (['wechat_enabled', 'epay_enabled', 'alipay_enabled', 'bark_enabled', 'bark_notify_register', 'bark_notify_recharge'] as $switchKey) {
        if ($out[$switchKey] === '' || $out[$switchKey] === null) {
            $out[$switchKey] = '0';
        }
    }
    ok($out);
}

function admin_settings_save()
{
    $fields = ['site_name', 'site_desc', 'announcement', 'parse_cost', 'register_points',
               'points_per_yuan', 'epay_enabled', 'epay_api', 'epay_pid', 'epay_public_key', 'epay_private_key', 'epay_pay_types',
             'alipay_enabled', 'alipay_app_id', 'alipay_private_key', 'alipay_public_key',
             'wechat_enabled', 'wechat_name', 'wechat_qrcode', 'wechat_desc',
             'bark_enabled', 'bark_server', 'bark_key', 'bark_sound', 'bark_notify_register', 'bark_notify_recharge',
             'share_title', 'share_desc', 'share_image'];
    // 数字字段必须为合法的非负整数
    $intFields = ['parse_cost', 'register_points', 'points_per_yuan', 'epay_enabled', 'alipay_enabled', 'wechat_enabled', 'bark_enabled', 'bark_notify_register', 'bark_notify_recharge'];
    foreach ($intFields as $k) {
        if (array_key_exists($k, $_POST) && preg_match('/^\d+$/', trim((string)$_POST[$k])) !== 1) {
            fail($k . ' 必须为非负整数');
        }
    }
    foreach ($fields as $k) {
        if (!array_key_exists($k, $_POST)) {
            continue;
        }
        $val = trim((string)$_POST[$k]);
        set_setting($k, $val);
    }
    ok();
}

function admin_apis()
{
    $page = max(1, (int)input('page', 1));
    $q = trim(input('q', ''));
    $where = '';
    $params = [];
    if ($q !== '') {
        $where = 'WHERE name LIKE ? OR slug LIKE ?';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $total = (int)DB::scalar('SELECT COUNT(*) FROM apis ' . $where, $params);
    $pageSize = 50;
    $pages = max(1, ceil($total / $pageSize));
    $offset = ($page - 1) * $pageSize;
    $rows = DB::all(
        'SELECT * FROM apis ' . $where . ' ORDER BY sort ASC, id ASC LIMIT ' . (int)$offset . ',' . (int)$pageSize,
        $params
    );
    ok(['list' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages]);
}

function admin_api_save()
{
    $id = (int)input('id', 0);
    $name = trim((string)input('name', ''));
    $slug = strtolower(trim((string)input('slug', '')));
    $icon = trim((string)input('icon', ''));
    $enabled = (int)input('enabled', 1) === 1 ? 1 : 0;
    $apiUrl = trim((string)input('api_url', ''));
    $respData = trim((string)input('resp_data', ''));
    $respTitle = trim((string)input('resp_title', ''));
    $respVideo = trim((string)input('resp_video', ''));
    $respImg = trim((string)input('resp_img', ''));
    $parseType = strtolower(trim((string)input('parse_type', '')));
    $sort = (int)input('sort', 0);

    if ($parseType !== '') {
        $pt = DB::one('SELECT id FROM parse_types WHERE `key`=? AND enabled=1', [$parseType]);
        if (!$pt) {
            fail('解析类型不合法');
        }
    }

    if ($name === '') {
        fail('接口名称不能为空');
    }
    if ($slug === '') {
        fail('接口标识不能为空');
    }
    if (!preg_match('/^[a-z0-9_\-\*]{1,32}$/', $slug)) {
        fail('接口标识只能包含小写字母、数字、下划线、中划线或 *');
    }
    if ($icon !== '' && !preg_match('#^https?://#i', $icon)) {
        fail('接口图标需为 http(s) 链接');
    }
    if ($apiUrl !== '' && !preg_match('#^https?://#i', $apiUrl)) {
        fail('接口地址需为 http(s) 链接');
    }

    // 同一平台标识下名称唯一（允许同一平台配置多个接口）
    $exist = DB::one('SELECT id FROM apis WHERE slug=? AND name=? AND id<>?', [$slug, $name, $id]);
    if ($exist) {
        fail('该平台下已存在同名接口');
    }

    if ($id > 0) {
        DB::execute(
            'UPDATE apis SET name=?,slug=?,icon=?,enabled=?,api_url=?,resp_data=?,resp_title=?,resp_video=?,resp_img=?,parse_type=?,sort=? WHERE id=?',
            [$name, $slug, $icon, $enabled, $apiUrl, $respData, $respTitle, $respVideo, $respImg, $parseType, $sort, $id]
        );
    } else {
        DB::execute(
            'INSERT INTO apis(name,slug,icon,enabled,api_url,resp_data,resp_title,resp_video,resp_img,parse_type,sort,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())',
            [$name, $slug, $icon, $enabled, $apiUrl, $respData, $respTitle, $respVideo, $respImg, $parseType, $sort]
        );
    }
    ok(['id' => $id > 0 ? $id : (int)DB::lastId()]);
}

function admin_api_delete()
{
    $id = (int)input('id', 0);
    $api = DB::one('SELECT * FROM apis WHERE id=?', [$id]);
    if (!$api) {
        fail('接口不存在');
    }
    DB::execute('DELETE FROM apis WHERE id=?', [$id]);
    ok();
}

function admin_api_toggle()
{
    $id = (int)input('id', 0);
    $enabled = (int)input('enabled', 1) === 1 ? 1 : 0;
    $api = DB::one('SELECT * FROM apis WHERE id=?', [$id]);
    if (!$api) {
        fail('接口不存在');
    }
    DB::execute('UPDATE apis SET enabled=? WHERE id=?', [$enabled, $id]);
    ok(['enabled' => $enabled]);
}

function admin_parse_types()
{
    $rows = DB::all('SELECT * FROM parse_types ORDER BY sort ASC, id ASC');
    ok(['list' => $rows]);
}

function admin_parse_type_save()
{
    $id = (int)input('id', 0);
    $name = trim((string)input('name', ''));
    $key = strtolower(trim((string)input('key', '')));
    $sort = (int)input('sort', 0);
    $enabled = (int)input('enabled', 1) === 1 ? 1 : 0;

    if ($name === '') {
        fail('类型名称不能为空');
    }
    if (!preg_match('/^[a-z0-9_\-]{1,32}$/', $key)) {
        fail('类型标识只能包含小写字母、数字、下划线或中划线');
    }

    $exist = DB::one('SELECT id FROM parse_types WHERE `key`=? AND id<>?', [$key, $id]);
    if ($exist) {
        fail('该类型标识已存在');
    }

    if ($id > 0) {
        DB::execute('UPDATE parse_types SET name=?,`key`=?,sort=?,enabled=? WHERE id=?', [$name, $key, $sort, $enabled, $id]);
    } else {
        DB::execute('INSERT INTO parse_types(name,`key`,sort,enabled,created_at) VALUES(?,?,?,?,NOW())', [$name, $key, $sort, $enabled]);
    }
    ok(['id' => $id > 0 ? $id : (int)DB::lastId()]);
}

function admin_parse_type_delete()
{
    $id = (int)input('id', 0);
    $pt = DB::one('SELECT * FROM parse_types WHERE id=?', [$id]);
    if (!$pt) {
        fail('解析类型不存在');
    }
    DB::execute('DELETE FROM parse_types WHERE id=?', [$id]);
    ok();
}

function admin_parse_type_toggle()
{
    $id = (int)input('id', 0);
    $enabled = (int)input('enabled', 1) === 1 ? 1 : 0;
    $pt = DB::one('SELECT * FROM parse_types WHERE id=?', [$id]);
    if (!$pt) {
        fail('解析类型不存在');
    }
    DB::execute('UPDATE parse_types SET enabled=? WHERE id=?', [$enabled, $id]);
    ok(['enabled' => $enabled]);
}

function admin_versions()
{
    $rows = DB::all('SELECT * FROM versions ORDER BY id DESC');
    $current = trim((string)setting('site_version', ''));
    ok(['list' => $rows, 'current' => $current]);
}

function admin_version_save()
{
    $id = (int)input('id', 0);
    $title = trim((string)input('title', ''));
    $type = trim((string)input('type', 'update'));
    $content = trim((string)input('content', ''));

    if ($title === '') {
        fail('更新标题不能为空');
    }
    if (!in_array($type, ['update', 'optimize', 'fix'], true)) {
        $type = 'update';
    }

    if ($id > 0) {
        $row = DB::one('SELECT id FROM versions WHERE id=?', [$id]);
        if (!$row) {
            fail('版本记录不存在');
        }
        DB::execute('UPDATE versions SET title=?,type=?,content=? WHERE id=?', [$title, $type, $content, $id]);
        ok(['id' => $id]);
    }

    $version = date('Y-m.d-H:i');
    DB::execute('INSERT INTO versions(version,title,type,content,created_at) VALUES(?,?,?,?,NOW())', [$version, $title, $type, $content]);
    set_setting('site_version', $version);
    ok(['id' => (int)DB::lastId(), 'version' => $version]);
}

function admin_version_delete()
{
    $id = (int)input('id', 0);
    $row = DB::one('SELECT id FROM versions WHERE id=?', [$id]);
    if (!$row) {
        fail('版本记录不存在');
    }
    DB::execute('DELETE FROM versions WHERE id=?', [$id]);
    $latest = DB::one('SELECT version FROM versions ORDER BY id DESC LIMIT 1');
    if ($latest && !empty($latest['version'])) {
        set_setting('site_version', $latest['version']);
    }
    ok();
}
