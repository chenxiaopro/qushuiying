<?php
require_once __DIR__ . '/../../app/init.php';
$isLogin = (bool)p_current_admin();
$isApi = (string)p_input('action', '') !== '';
if ($isApi) {
    require __DIR__ . '/api.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>易支付平台后台</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<?php if (!$isLogin): ?>
<div class="login-wrap">
  <div class="login-card">
    <h1>易支付平台后台</h1>
    <div id="login-msg"></div>
    <div class="form-item">
      <label>用户名</label>
      <input type="text" id="username" autocomplete="username">
    </div>
    <div class="form-item">
      <label>密码</label>
      <input type="password" id="password" autocomplete="current-password">
    </div>
    <button class="btn-primary" id="btn-login">登 录</button>
  </div>
</div>
<script>
document.getElementById('btn-login').addEventListener('click', function(){
  var u = document.getElementById('username').value.trim();
  var p = document.getElementById('password').value;
  if (!u || !p) { showMsg('请输入用户名和密码'); return; }
  fetch('?action=login', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=login&username=' + encodeURIComponent(u) + '&password=' + encodeURIComponent(p)
  }).then(function(r){return r.json();}).then(function(j){
    if (j.code === 0) { CSRF = j.data.csrf; location.reload(); }
    else showMsg(j.msg);
  });
});
function showMsg(m){ var el = document.getElementById('login-msg'); el.className = 'msg msg-error'; el.textContent = m; }
</script>
<?php else: ?>

<div class="admin-layout">
  <aside class="sidebar">
    <div class="logo">易支付平台</div>
    <nav>
      <a href="#" data-page="dashboard" class="active">控制台</a>
      <a href="#" data-page="merchant">商户管理</a>
      <a href="#" data-page="channel">通道管理</a>
      <a href="#" data-page="order">订单查询</a>
      <a href="#" id="btn-logout">退出登录</a>
    </nav>
  </aside>
  <main class="main">
    <div id="page-dashboard" class="page">
      <h2>控制台</h2>
      <div class="panel">
        <div id="stats" style="display:flex;gap:16px;flex-wrap:wrap;">
          <div>商户数：<b id="s-merchant">-</b></div>
          <div>通道数：<b id="s-channel">-</b></div>
          <div>订单数：<b id="s-order">-</b></div>
          <div>累计收款：<b id="s-paid">-</b></div>
          <div>今日收款：<b id="s-today">-</b></div>
        </div>
      </div>
    </div>

    <div id="page-merchant" class="page" style="display:none;">
      <h2>商户管理</h2>
      <div class="panel">
        <div class="toolbar">
          <div>商户列表</div>
          <button class="btn-sm" id="btn-add-merchant">新增商户</button>
        </div>
        <table class="table">
          <thead><tr><th>ID</th><th>名称</th><th>商户号</th><th>密钥</th><th>状态</th><th>操作</th></tr></thead>
          <tbody id="merchant-tbody"></tbody>
        </table>
      </div>
    </div>

    <div id="page-channel" class="page" style="display:none;">
      <h2>通道管理</h2>
      <div class="panel">
        <div class="toolbar">
          <div>支付通道</div>
          <button class="btn-sm" id="btn-add-channel">新增通道</button>
        </div>
        <table class="table">
          <thead><tr><th>ID</th><th>名称</th><th>编码</th><th>支付方式</th><th>状态</th><th>操作</th></tr></thead>
          <tbody id="channel-tbody"></tbody>
        </table>
      </div>
    </div>

    <div id="page-order" class="page" style="display:none;">
      <h2>订单查询</h2>
      <div class="panel">
        <div class="toolbar">
          <div>
            <input type="text" id="order-kw" placeholder="订单号/商户订单号" style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;width:220px;">
            <select id="order-status" style="padding:6px;border:1px solid #ddd;border-radius:6px;">
              <option value="">全部状态</option>
              <option value="0">待支付</option>
              <option value="1">已支付</option>
              <option value="2">已关闭</option>
            </select>
            <button class="btn-sm" id="btn-order-search">查询</button>
          </div>
        </div>
        <table class="table">
          <thead><tr><th>平台单号</th><th>商户单号</th><th>商户</th><th>通道</th><th>金额</th><th>状态</th><th>时间</th></tr></thead>
          <tbody id="order-tbody"></tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<script>
var CSRF = '<?php echo htmlspecialchars(p_csrf_token(), ENT_QUOTES); ?>';
var CHANNEL_OPTIONS = [];

function api(action, data, isJson){
  data = data || {};
  if (action !== 'login') data.csrf = CSRF;
  var body;
  var headers = {};
  if (isJson) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(data); }
  else { headers['Content-Type'] = 'application/x-www-form-urlencoded'; body = Object.keys(data).map(function(k){ return k + '=' + encodeURIComponent(data[k]); }).join('&'); }
  if (action) body = (body ? 'action=' + action + '&' + body : 'action=' + action);
  return fetch('?', {method:'POST', headers:headers, body:body}).then(function(r){return r.json();});
}

function switchPage(name){
  document.querySelectorAll('.page').forEach(function(p){ p.style.display = 'none'; });
  document.getElementById('page-' + name).style.display = 'block';
  document.querySelectorAll('.sidebar nav a[data-page]').forEach(function(a){
    a.classList.toggle('active', a.getAttribute('data-page') === name);
  });
}

document.querySelectorAll('.sidebar nav a[data-page]').forEach(function(a){
  a.addEventListener('click', function(e){ e.preventDefault(); switchPage(a.getAttribute('data-page')); });
});

document.getElementById('btn-logout').addEventListener('click', function(){
  api('logout', {}).then(function(){ location.reload(); });
});

function loadStats(){
  api('stats', {}).then(function(j){
    if (j.code === 0) {
      document.getElementById('s-merchant').textContent = j.data.merchant_count;
      document.getElementById('s-channel').textContent = j.data.channel_count;
      document.getElementById('s-order').textContent = j.data.order_count;
      document.getElementById('s-paid').textContent = '¥' + j.data.paid_amount.toFixed(2);
      document.getElementById('s-today').textContent = '¥' + j.data.today_amount.toFixed(2);
    }
  });
}

function loadMerchants(){
  api('merchant_list', {}).then(function(j){
    var tb = document.getElementById('merchant-tbody');
    tb.innerHTML = '';
    (j.data||[]).forEach(function(m){
      var tr = document.createElement('tr');
      tr.innerHTML = '<td>'+m.id+'</td><td>'+esc(m.name)+'</td><td>'+esc(m.pid)+'</td><td><code>'+esc(m.secret)+'</code></td>'
        + '<td><span class="tag tag-'+(m.status==1?1:2)+'">'+(m.status==1?'正常':'禁用')+'</span></td>'
        + '<td><button class="btn-sm2" onclick="toggleMerchant('+m.id+','+(m.status==1?0:1)+')">'+(m.status==1?'禁用':'启用')+'</button> '
        + '<button class="btn-sm2" onclick="resetKey('+m.id+')">重置密钥</button> '
        + '<button class="btn-danger" onclick="delMerchant('+m.id+')">删除</button></td>';
      tb.appendChild(tr);
    });
  });
}

document.getElementById('btn-add-merchant').addEventListener('click', function(){
  var name = prompt('请输入商户名称：');
  if (!name) return;
  api('merchant_save', {name: name}).then(function(j){
    if (j.code === 0) { alert('创建成功，商户号：' + j.data.pid + '\n密钥：' + j.data.secret); loadMerchants(); loadStats(); }
    else alert(j.msg);
  });
});

function toggleMerchant(id, status){
  api('merchant_save', {id: id, status: status, name: ''}).then(function(j){
    if (j.code === 0) loadMerchants();
  });
}
function resetKey(id){
  if (!confirm('确定重置该商户密钥？')) return;
  api('merchant_reset_key', {id: id}).then(function(j){
    if (j.code === 0) alert('新密钥：' + j.data.secret); loadMerchants();
  });
}
function delMerchant(id){
  if (!confirm('确定删除该商户？')) return;
  api('merchant_delete', {id: id}).then(function(j){ loadMerchants(); loadStats(); });
}

function loadChannels(){
  api('channel_list', {}).then(function(j){
    CHANNEL_OPTIONS = j.data || [];
    var tb = document.getElementById('channel-tbody');
    tb.innerHTML = '';
    (j.data||[]).forEach(function(c){
      var tr = document.createElement('tr');
      tr.innerHTML = '<td>'+c.id+'</td><td>'+esc(c.name)+'</td><td>'+esc(c.code)+'</td><td>'+esc(c.pay_type)+'</td>'
        + '<td><span class="tag tag-'+(c.enabled==1?1:2)+'">'+(c.enabled==1?'启用':'禁用')+'</span></td>'
        + '<td><button class="btn-sm2" onclick="editChannel('+c.id+')">编辑</button> '
        + '<button class="btn-danger" onclick="delChannel('+c.id+')">删除</button></td>';
      tb.appendChild(tr);
    });
  });
}

document.getElementById('btn-add-channel').addEventListener('click', function(){ showChannelForm(null); });

function showChannelForm(ch){
  var name = ch ? ch.name : '';
  var code = ch ? ch.code : 'alipay_f2f';
  var payType = ch ? ch.pay_type : 'alipay';
  var config = ch && ch.config ? ch.config : '';
  var enabled = ch ? ch.enabled : 1;
  var overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;z-index:99;';
  overlay.innerHTML = '<div style="background:#fff;border-radius:10px;padding:24px;width:560px;max-height:90vh;overflow:auto;">'
    + '<h3 style="margin-bottom:16px;">'+(ch?'编辑通道':'新增通道')+'</h3>'
    + '<div class="form-item"><label>名称</label><input id="f-name" value="'+esc(name)+'"></div>'
    + '<div class="form-item"><label>通道编码</label><select id="f-code">'
    + '<option value="alipay_f2f"'+(code==='alipay_f2f'?' selected':'')+'>支付宝当面付</option>'
    + '<option value="wechat_native"'+(code==='wechat_native'?' selected':'')+'>微信Native</option>'
    + '<option value="wechat_h5"'+(code==='wechat_h5'?' selected':'')+'>微信H5</option>'
    + '<option value="wechat_jsapi"'+(code==='wechat_jsapi'?' selected':'')+'>微信JSAPI</option>'
    + '<option value="upstream_epay"'+(code==='upstream_epay'?' selected':'')+'>上游易支付转发</option>'
    + '</select></div>'
    + '<div class="form-item"><label>支付方式标识(pay_type)</label><input id="f-paytype" value="'+esc(payType)+'" placeholder="alipay / wxpay"></div>'
    + '<div class="form-item"><label>配置 JSON</label><textarea id="f-config" placeholder=\'{"app_id":"...","merchant_private_key":"..."}\'>'+esc(config)+'</textarea></div>'
    + '<div class="form-item"><label>状态</label><select id="f-enabled"><option value="1"'+(enabled==1?' selected':'')+'>启用</option><option value="0"'+(enabled==0?' selected':'')+'>禁用</option></select></div>'
    + '<button class="btn-primary" id="f-save">保存</button>'
    + '</div>';
  document.body.appendChild(overlay);
  document.getElementById('f-save').addEventListener('click', function(){
    var data = {
      id: ch ? ch.id : 0,
      name: document.getElementById('f-name').value.trim(),
      code: document.getElementById('f-code').value,
      pay_type: document.getElementById('f-paytype').value.trim(),
      config: document.getElementById('f-config').value,
      enabled: document.getElementById('f-enabled').value
    };
    api('channel_save', data).then(function(j){
      if (j.code === 0) { overlay.remove(); loadChannels(); loadStats(); }
      else alert(j.msg);
    });
  });
  overlay.addEventListener('click', function(e){ if (e.target === overlay) overlay.remove(); });
}

function editChannel(id){
  api('channel_get', {id: id}).then(function(j){
    if (j.code === 0) showChannelForm(j.data);
  });
}
function delChannel(id){
  if (!confirm('确定删除该通道？')) return;
  api('channel_delete', {id: id}).then(function(j){ loadChannels(); loadStats(); });
}

function loadOrders(page){
  page = page || 1;
  api('order_list', {page: page, kw: document.getElementById('order-kw').value.trim(), status: document.getElementById('order-status').value}).then(function(j){
    var tb = document.getElementById('order-tbody');
    tb.innerHTML = '';
    (j.data.list||[]).forEach(function(o){
      var statusText = o.status == 1 ? '已支付' : (o.status == 2 ? '已关闭' : '待支付');
      var tagCls = o.status == 1 ? '1' : (o.status == 2 ? '2' : '0');
      var tr = document.createElement('tr');
      tr.innerHTML = '<td>'+esc(o.trade_no)+'</td><td>'+esc(o.out_trade_no)+'</td><td>'+esc(o.merchant_name||'')+'</td>'
        + '<td>'+esc(o.channel_name||'')+'</td><td>¥'+o.money+'</td>'
        + '<td><span class="tag tag-'+tagCls+'">'+statusText+'</span></td><td>'+esc(o.created_at)+'</td>';
      tb.appendChild(tr);
    });
  });
}
document.getElementById('btn-order-search').addEventListener('click', function(){ loadOrders(1); });

function esc(s){ var d=document.createElement('div'); d.textContent = s==null?'':String(s); return d.innerHTML; }

switchPage('dashboard');
loadStats();
loadMerchants();
loadChannels();
loadOrders(1);
</script>
<?php endif; ?>
</body>
</html>
