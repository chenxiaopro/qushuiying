<?php
/**
 * 安装向导
 * 访问 /install.php 完成安装（数据库 + 管理员账号 + 配置写入）
 * 安装完成后请务必删除本目录
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Shanghai');

$localFile = dirname(__DIR__) . '/app/config.local.php';
$installed = is_file($localFile);

$error = '';
$done = false;

// 处理提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    $host = trim($_POST['host'] ?? '127.0.0.1');
    $port = (int)($_POST['port'] ?? 3306);
    $user = trim($_POST['dbuser'] ?? '');
    $pass = (string)($_POST['dbpass'] ?? '');
    $dbname = trim($_POST['dbname'] ?? '');
    $adminUser = trim($_POST['adminuser'] ?? '');
    $adminPass = (string)($_POST['adminpass'] ?? '');
    $siteUrl = trim($_POST['siteurl'] ?? '');

    try {
        if ($host === '' || $user === '' || $dbname === '' || $adminUser === '' || $adminPass === '') {
            throw new Exception('请填写所有必填项');
        }
        if (mb_strlen($adminPass) < 6) {
            throw new Exception('管理员密码至少 6 位');
        }
        if (!extension_loaded('pdo_mysql')) {
            throw new Exception('服务器 PHP 缺少 pdo_mysql 扩展');
        }
        if (!extension_loaded('curl')) {
            throw new Exception('服务器 PHP 缺少 curl 扩展');
        }

        // 连接(先不选库，不存在则创建)
        $dsn0 = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
        $pdo = new PDO($dsn0, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $exists = $pdo->query('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=' . $pdo->quote($dbname))->fetchColumn();
        if (!$exists) {
            $pdo->exec('CREATE DATABASE `' . str_replace('`', '', $dbname) . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        }
        $pdo->exec('USE `' . str_replace('`', '', $dbname) . '`');

        // 执行建表 SQL
        $sqlFile = dirname(__DIR__) . '/sql/install.sql';
        if (!is_file($sqlFile)) {
            throw new Exception('缺少 sql/install.sql');
        }
        $sql = file_get_contents($sqlFile);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt !== '' && stripos($stmt, '--') !== 0) {
                $pdo->exec($stmt);
            }
        }

        // 迁移：为旧版 apis 表补充 parse_type 字段
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `apis` LIKE 'parse_type'")->fetchAll();
            if (!$cols) {
                $pdo->exec("ALTER TABLE `apis` ADD COLUMN `parse_type` VARCHAR(20) NULL DEFAULT '' COMMENT '解析类型(dyzy主页/dsp短视频图集/douyinyh原画质/live实况，空=自动识别)'");
            }
        } catch (Exception $e) {
            // 表不存在或字段已存在时忽略
        }

        // 创建管理员（已存在则更新密码，避免重复安装时唯一键冲突）
        $stmt = $pdo->prepare('INSERT INTO admins(username,password,created_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE password=VALUES(password)');
        $stmt->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT)]);

        // 写入配置
        $configContent = "<?php\n// 本地部署配置（由安装向导生成）\nreturn [\n" .
            "    'db' => [\n" .
            "        'host' => " . var_export($host, true) . ",\n" .
            "        'port' => " . $port . ",\n" .
            "        'name' => " . var_export($dbname, true) . ",\n" .
            "        'user' => " . var_export($user, true) . ",\n" .
            "        'pass' => " . var_export($pass, true) . ",\n" .
            "    ],\n" .
            "    'site_url' => " . var_export($siteUrl, true) . ",\n" .
            "    'debug' => false,\n" .
            "];\n";
        file_put_contents($localFile, $configContent);
        chmod($localFile, 0644);

        $done = true;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装向导 - 短视频去水印</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:linear-gradient(135deg,#6d5bff,#38bdf8);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.box{background:#fff;border-radius:16px;padding:30px;width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
h1{font-size:20px;margin-bottom:6px}
p.sub{color:#8a90a3;font-size:13px;margin-bottom:18px}
label{display:block;font-size:13px;color:#8a90a3;margin:12px 0 6px}
input{border:1px solid #dfe3ee;border-radius:8px;padding:10px 12px;font-size:14px;width:100%;outline:none}
input:focus{border-color:#6d5bff}
.row{display:flex;gap:12px}
.row>div{flex:1}
button{margin-top:20px;width:100%;padding:12px;border:0;border-radius:8px;background:#6d5bff;color:#fff;font-size:15px;cursor:pointer}
button:hover{opacity:.88}
.err{background:#ffeaea;color:#ff5d5d;border-radius:8px;padding:10px 14px;font-size:13px;margin-top:14px}
.ok{background:#e5f7ec;color:#17a75b;border-radius:8px;padding:14px;font-size:13px;margin-top:14px}
.ok b{display:block;font-size:15px;margin-bottom:6px}
.note{background:#f5f6fb;border-radius:8px;padding:10px 14px;font-size:12px;color:#8a90a3;margin-top:14px}
</style>
</head>
<body>
<div class="box">
  <?php if ($installed): ?>
    <h1>系统已安装</h1>
    <p class="sub">检测到配置已存在，如需重装请删除 app/config.local.php</p>
    <div class="note">删除 app/config.local.php 后重新访问本页即可重装。数据库数据会保留，重复安装会更新管理员密码。</div>
  <?php elseif ($done): ?>
    <h1>安装成功</h1>
    <div class="ok">
      <b>部署完成</b>
      1. 管理员账号：<code><?= htmlspecialchars($adminUser) ?></code><br>
      2. 前台地址：<code>/index.php</code><br>
      3. 后台地址：<code>/admin/index.php</code><br>
      4. <b>请立即删除本文件 install.php</b>，防止被他人重装
    </div>
  <?php else: ?>
    <h1>安装向导</h1>
    <p class="sub">短视频去水印系统 · 请填写数据库和管理员信息</p>

    <form method="post">
      <label>数据库地址</label>
      <div class="row">
        <div><input name="host" value="127.0.0.1" placeholder="数据库地址" required></div>
        <div style="flex:0 0 110px"><input name="port" value="3306" placeholder="端口" required></div>
      </div>
      <label>数据库账号</label>
      <input name="dbuser" placeholder="数据库用户名" required>
      <label>数据库密码</label>
      <input name="dbpass" type="password" placeholder="数据库密码">
      <label>数据库名</label>
      <input name="dbname" value="wm" placeholder="数据库名(不存在将自动创建)" required>

      <label>管理员账号</label>
      <input name="adminuser" placeholder="后台管理员用户名" required>
      <label>管理员密码（至少 6 位）</label>
      <input name="adminpass" type="password" placeholder="后台管理员密码" required>

      <label>站点地址（可留空自动识别，如 https://wm.example.com）</label>
      <input name="siteurl" placeholder="https://wm.example.com">

      <button type="submit">开始安装</button>
      <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    </form>

    <div class="note">运行环境要求：PHP 7.4+（建议 8.0+）、pdo_mysql、curl、fileinfo、openssl 扩展</div>
  <?php endif; ?>
</div>
</body>
</html>
