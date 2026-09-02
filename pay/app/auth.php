<?php
/**
 * 会话与认证（平台管理员）
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

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
    session_name('PAYSESS');
    session_start();
}

function p_current_admin()
{
    $id = (int)($_SESSION['pay_admin_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    static $cache = [];
    if (!isset($cache[$id])) {
        $cache[$id] = PDB::one('SELECT id,username FROM pay_admins WHERE id=?', [$id]);
    }
    return $cache[$id];
}

function p_require_admin()
{
    $a = p_current_admin();
    if (!$a) {
        p_fail('请先登录', 401);
    }
    return $a;
}
