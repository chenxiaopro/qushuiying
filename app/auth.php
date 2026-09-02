<?php
/**
 * 会话与认证
 */

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
    session_name('WMSESS');
    session_start();
}

// 会话已过期但存在"30天免登录"Cookie 时，自动恢复登录
if (empty($_SESSION['user_id'])) {
    try_remember_login();
}

/** 校验"30天免登录"Cookie 并自动登录 */
function try_remember_login()
{
    $cookie = $_COOKIE['wm_remember'] ?? '';
    if ($cookie === '') {
        return;
    }
    $parts = explode(':', $cookie, 2);
    if (count($parts) !== 2) {
        return;
    }
    $userId = (int)$parts[0];
    $token = $parts[1];
    if ($userId <= 0 || $token === '') {
        return;
    }
    $u = DB::one('SELECT id, remember_token, status FROM users WHERE id=?', [$userId]);
    if (!$u || (int)$u['status'] !== 1 || $u['remember_token'] === null || $u['remember_token'] === '') {
        return;
    }
    if (!hash_equals((string)$u['remember_token'], hash('sha256', $token))) {
        return;
    }
    $_SESSION['user_id'] = $userId;
}

/** 当前登录用户行或 null */
function current_user()
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    static $cache = [];
    if (!isset($cache[$id])) {
        $cache[$id] = DB::one('SELECT id,username,points,total_points,status,created_at FROM users WHERE id=?', [$id]);
    }
    return $cache[$id];
}

/** 要求登录，未登录直接失败退出 */
function require_login()
{
    $u = current_user();
    if (!$u) {
        fail('请先登录', 401);
    }
    if ((int)$u['status'] !== 1) {
        fail('账号已被禁用', 403);
    }
    return $u;
}

/** 当前管理员或 null */
function current_admin()
{
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    static $cache = [];
    if (!isset($cache[$id])) {
        $cache[$id] = DB::one('SELECT id,username FROM admins WHERE id=?', [$id]);
    }
    return $cache[$id];
}

function require_admin()
{
    $a = current_admin();
    if (!$a) {
        fail('请先登录', 401);
    }
    return $a;
}

/** 用户加点数（原子操作） */
function add_points($userId, $points)
{
    return DB::execute('UPDATE users SET points=points+?, total_points=total_points+? WHERE id=?', [$points, $points, $userId]);
}

/** 扣点数（原子操作，余额不足返回 false） */
function deduct_points($userId, $points)
{
    if ($points <= 0) {
        return true;
    }
    return DB::execute('UPDATE users SET points=points-? WHERE id=? AND points>=?', [$points, $userId, $points]) > 0;
}

/** 解析频率限制：同一用户 2 秒内仅允许一次，防止刷接口 */
function check_parse_rate_limit($userId)
{
    $u = DB::one('SELECT last_parse_ts FROM users WHERE id=?', [$userId]);
    $last = (int)($u['last_parse_ts'] ?? 0);
    $now = time();
    if ($last && ($now - $last) < 2) {
        fail('操作过于频繁，请稍后再试', 429);
    }
    DB::execute('UPDATE users SET last_parse_ts=? WHERE id=?', [$now, $userId]);
    return true;
}
