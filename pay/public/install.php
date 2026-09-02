<?php
/**
 * 易支付平台安装向导
 * 生成 config.local.php，初始化数据库，创建管理员账号
 */

$configFile = __DIR__ . '/../app/config.local.php';
$installed = is_file($configFile);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'config') {
            $host = trim((string)($_POST['db_host'] ?? ''));
            $port = (int)($_POST['db_port'] ?? 3306);
            $name = trim((string)($_POST['db_name'] ?? ''));
            $user = trim((string)($_POST['db_user'] ?? ''));
            $pass = (string)($_POST['db_pass'] ?? '');
            if ($host === '' || $name === '' || $user === '') {
                throw new RuntimeException('请填写完整的数据库信息');
            }
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $name) . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('USE `' . str_replace('`', '', $name) . '`');

            $sql = file_get_contents(__DIR__ . '/../sql/install.sql');
            $pdo->exec($sql);

            $cfg = "<?php\nreturn " . var_export([
                'db' => ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => $pass],
                'site_url' => '',
                'debug' => false,
            ], true) . ";\n";
            file_put_contents($configFile, $cfg);

            $_SESSION['pay_install'] = true;
            echo json_encode(['code' => 0, 'msg' => 'ok']);
            exit;
        }

        if ($action === 'admin') {
            if (empty($_SESSION['pay_install']) && !is_file($configFile)) {
                throw new RuntimeException('请先完成数据库配置');
            }
            require_once __DIR__ . '/../app/init.php';
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if (strlen($username) < 3 || strlen($password) < 6) {
                throw new RuntimeException('用户名至少3位，密码至少6位');
            }
            if (PDB::scalar('SELECT COUNT(*) FROM pay_admins') > 0) {
                throw new RuntimeException('已存在管理员，请直接登录');
            }
            PDB::execute('INSERT INTO pay_admins(username,password) VALUES(?,?)', [$username, password_hash($password, PASSWORD_DEFAULT)]);
            unset($_SESSION['pay_install']);
            echo json_encode(['code' => 0, 'msg' => 'ok']);
            exit;
        }
        throw new RuntimeException('未知操作');
    } catch (Exception $e) {
        echo json_encode(['code' => 1, 'msg' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>易支付平台 - 安装向导</title>
<link rel="stylesheet" href="./assets/css/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card" style="max-width:460px;">
    <h1>易支付平台安装</h1>
    <div id="msg"></div>
    <div id="step-config">
      <div class="form-item"><label>数据库地址</label><input id="db_host" value="127.0.0.1"></div>
      <div class="form-item"><label>数据库端口</label><input id="db_port" value="3306"></div>
      <div class="form-item"><label>数据库名</label><input id="db_name" value="pay"></div>
      <div class="form-item"><label>数据库用户</label><input id="db_user" value="root"></div>
      <div class="form-item"><label>数据库密码</label><input id="db_pass" type="password"></div>
      <button class="btn-primary" id="btn-config">下一步：初始化数据库</button>
    </div>
    <div id="step-admin" style="display:none;">
      <div class="form-item"><label>管理员用户名</label><input id="username" value="admin"></div>
      <div class="form-item"><label>管理员密码</label><input id="password" type="password"></div>
      <button class="btn-primary" id="btn-admin">创建管理员并完成安装</button>
    </div>
    <?php if ($installed): ?><p style="margin-top:16px;color:#999;font-size:13px;text-align:center;">检测到已安装，如需重装请删除 app/config.local.php</p><?php endif; ?>
  </div>
</div>
<script>
function post(action, data){
  var body = 'action=' + action;
  Object.keys(data).forEach(function(k){ body += '&' + k + '=' + encodeURIComponent(data[k]); });
  return fetch('?', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body}).then(function(r){return r.json();});
}
function show(m, err){ var el = document.getElementById('msg'); el.className = 'msg ' + (err?'msg-error':'msg-ok'); el.textContent = m; }
document.getElementById('btn-config').addEventListener('click', function(){
  post('config', {
    db_host: document.getElementById('db_host').value.trim(),
    db_port: document.getElementById('db_port').value.trim(),
    db_name: document.getElementById('db_name').value.trim(),
    db_user: document.getElementById('db_user').value.trim(),
    db_pass: document.getElementById('db_pass').value
  }).then(function(j){
    if (j.code === 0) { show('数据库初始化成功'); document.getElementById('step-config').style.display='none'; document.getElementById('step-admin').style.display='block'; }
    else show(j.msg, true);
  });
});
document.getElementById('btn-admin').addEventListener('click', function(){
  post('admin', {
    username: document.getElementById('username').value.trim(),
    password: document.getElementById('password').value
  }).then(function(j){
    if (j.code === 0) { show('安装完成，正在跳转...'); setTimeout(function(){ location.href = './admin/'; }, 1200); }
    else show(j.msg, true);
  });
});
</script>
</body>
</html>
