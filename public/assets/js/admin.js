/* 后台管理交互 */
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var toastTimer = null;
  var state = { page: 1 };

  function toast(msg) {
    var t = $('toast');
    t.textContent = msg;
    t.classList.remove('hide');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.add('hide'); }, 2400);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function adminApi(action, params) {
    var body = new URLSearchParams();
    body.append('action', action);
    if (window.ADMIN && window.ADMIN.csrf) body.append('csrf', window.ADMIN.csrf);
    Object.keys(params || {}).forEach(function (k) { body.append(k, params[k]); });
    return fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (res && res.data && res.data.csrf) window.ADMIN.csrf = res.data.csrf;
      return res;
    });
  }

  function checkAuth(res) {
    if (res.code === 401) {
      loggedIn = false;
      renderAuth();
      return false;
    }
    return true;
  }

  /* ---------- 登录 ---------- */
  var loggedIn = false;
  function renderAuth() {
    $('loginView').classList.toggle('hide', loggedIn);
    $('appView').classList.toggle('hide', !loggedIn);
    if (loggedIn) { loadPage('dashboard'); loadParseTypesCache(); }
  }
  $('adminLoginBtn').addEventListener('click', function () {
    adminApi('login', { username: $('adminUser').value.trim(), password: $('adminPass').value })
      .then(function (res) {
        if (res.code === 0) {
          loggedIn = true;
          renderAuth();
          toast('登录成功');
        } else { toast(res.msg); }
      });
  });
  $('adminPass').addEventListener('keydown', function (e) { if (e.key === 'Enter') $('adminLoginBtn').click(); });
  $('adminLogout').addEventListener('click', function () {
    adminApi('logout').then(function () { loggedIn = false; renderAuth(); toast('已退出'); });
  });

  /* ---------- 导航 ---------- */
  document.querySelectorAll('.nav-item').forEach(function (el) {
    el.addEventListener('click', function () {
      document.querySelectorAll('.nav-item').forEach(function (x) { x.classList.remove('active'); });
      el.classList.add('active');
      loadPage(el.getAttribute('data-page'));
    });
  });

  function loadPage(name) {
    document.querySelectorAll('.page').forEach(function (p) {
      p.classList.toggle('hide', p.getAttribute('data-name') !== name);
    });
    if (name === 'dashboard') loadStats();
    if (name === 'users') loadUsers(1);
    if (name === 'orders') loadOrders(1, $('orderStatus').value);
    if (name === 'logs') loadLogs(1);
    if (name === 'cards') loadCards(1);
    if (name === 'apis') loadApis(1);
    if (name === 'parseTypes') loadParseTypes();
    if (name === 'versions') loadVersions();
    if (name === 'settings') loadSettings();
  }

  /* ---------- 仪表盘 ---------- */
  function loadStats() {
    adminApi('stats').then(function (res) {
      if (!checkAuth(res)) return;
      var d = res.data;
      var items = [
        ['用户总数', d.users],
        ['今日新增', d.users_today],
        ['有效订单', d.orders],
        ['总营收(元)', d.income.toFixed(2)],
        ['解析次数', d.parses],
        ['今日解析', d.parses_today],
        ['累计充值点数', d.points_total],
        ['未用卡密', d.cards_unused],
      ];
      $('statsGrid').innerHTML = items.map(function (x) {
        return '<div class="stat"><b>' + esc(x[1]) + '</b><span>' + esc(x[0]) + '</span></div>';
      }).join('');
      var recent = d.recent_parses || [];
      if (!recent.length) {
        $('recentParses').innerHTML = '<p style="color:#8a90a3">暂无解析记录</p>';
      } else {
        $('recentParses').innerHTML =
          '<div class="table-wrap"><table><thead><tr><th>用户</th><th>平台</th><th>标题</th><th>消耗</th><th>时间</th></tr></thead><tbody>' +
          recent.map(function (p) {
            return '<tr>' +
              '<td>' + esc(p.username || '-') + '</td>' +
              '<td>' + esc(p.platform || '-') + '</td>' +
              '<td>' + esc(p.title || '-') + '</td>' +
              '<td>' + p.cost + '</td>' +
              '<td>' + esc(p.created_at) + '</td>' +
              '</tr>';
          }).join('') + '</tbody></table></div>';
      }
    });
  }

  /* ---------- 用户管理 ---------- */
  function loadUsers(page, q) {
    state.page = page || 1;
    adminApi('users', { page: state.page, q: q || $('userSearch').value.trim() })
      .then(function (res) {
        if (!checkAuth(res)) return;
        var d = res.data;
        if (!d.list.length) { $('usersTable').innerHTML = '<p style="color:#8a90a3">暂无数据</p>'; }
        else {
          $('usersTable').innerHTML =
            '<div class="table-wrap"><table><thead><tr><th>ID</th><th>用户名</th><th>余额</th><th>累计充值</th><th>状态</th><th>注册时间</th><th>最近登录</th><th>操作</th></tr></thead><tbody>' +
            d.list.map(function (u) {
              return '<tr>' +
                '<td>' + u.id + '</td>' +
                '<td>' + esc(u.username) + '</td>' +
                '<td><b>' + u.points + '</b></td>' +
                '<td>' + u.total_points + '</td>' +
                '<td>' + (u.status == 1 ? '<span class="badge-ok">正常</span>' : '<span class="badge-off">禁用</span>') + '</td>' +
                '<td>' + esc(u.created_at) + '</td>' +
                '<td>' + esc(u.last_login_at || '-') + '</td>' +
                '<td>' +
                  '<button class="btn btn-sm" data-user-action="add" data-id="' + u.id + '">加点</button> ' +
                  '<button class="btn btn-sm" data-user-action="deduct" data-id="' + u.id + '">扣点</button> ' +
                  '<button class="btn btn-sm" data-user-action="toggle" data-id="' + u.id + '">' + (u.status == 1 ? '禁用' : '启用') + '</button> ' +
                  '<button class="btn btn-sm" data-user-action="reset" data-id="' + u.id + '">重置密码</button>' +
                '</td></tr>';
            }).join('') + '</tbody></table></div>';
          bindUserActions();
        }
        $('usersPager').innerHTML = pager(d.page, d.pages, function (p) { loadUsers(p); });
      });
  }
  $('userSearchBtn').addEventListener('click', function () { loadUsers(1); });
  $('userSearch').addEventListener('keydown', function (e) { if (e.key === 'Enter') loadUsers(1); });

  function bindUserActions() {
    document.querySelectorAll('[data-user-action]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-id');
        var act = b.getAttribute('data-user-action');
        if (act === 'toggle') {
          adminApi('user_action', { id: id, sub: 'toggle' }).then(function (res) {
            if (!checkAuth(res)) return;
            toast('已更新'); loadUsers(state.page);
          });
        } else if (act === 'add' || act === 'deduct') {
          openUserModal(id, act === 'add' ? '增加点数' : '扣除点数', 'points');
        } else if (act === 'reset') {
          openUserModal(id, '重置密码', 'password');
        }
      });
    });
  }

  function openUserModal(id, title, type) {
    $('userModalTitle').textContent = title;
    if (type === 'points') {
      $('userModalBody').innerHTML =
        '<label>点数</label><input class="field" id="userModalVal" type="number" min="1">' +
        '<button class="btn btn-primary btn-block" style="margin-top:16px" id="userModalOk">确定</button>';
    } else {
      $('userModalBody').innerHTML =
        '<label>新密码（6-32位）</label><input class="field" id="userModalVal" type="text" maxlength="32">' +
        '<button class="btn btn-primary btn-block" style="margin-top:16px" id="userModalOk">确定</button>';
    }
    $('userModal').classList.remove('hide');
    $('userModalOk').addEventListener('click', function () {
      var val = $('userModalVal').value.trim();
      if (!val) { toast('请输入数值'); return; }
      var action = type === 'points' ? (title.indexOf('增加') >= 0 ? 'add_points' : 'deduct_points') : 'reset_pwd';
      adminApi('user_action', { id: id, sub: action, points: val, password: val }).then(function (res) {
        if (!checkAuth(res)) return;
        if (res.code !== 0) { toast(res.msg); return; }
        $('userModal').classList.add('hide');
        toast('操作成功');
        loadUsers(state.page);
      });
    });
  }
  $('userModal').addEventListener('click', function (e) { if (e.target === this) this.classList.add('hide'); });

  /* ---------- 订单 ---------- */
  function loadOrders(page, status) {
    state.page = page || 1;
    adminApi('orders', { page: state.page, status: status || '' }).then(function (res) {
      if (!checkAuth(res)) return;
      var d = res.data;
      if (!d.list.length) { $('ordersTable').innerHTML = '<p style="color:#8a90a3">暂无数据</p>'; }
      else {
        var sMap = ['<span class="badge-wait">待支付</span>', '<span class="badge-ok">已支付</span>', '<span class="badge-off">已关闭</span>'];
        var tMap = { alipay: '支付宝', wxpay: '微信' };
        $('ordersTable').innerHTML =
          '<div class="table-wrap"><table><thead><tr><th>订单号</th><th>用户</th><th>金额</th><th>点数</th><th>方式</th><th>状态</th><th>支付时间</th><th>创建时间</th></tr></thead><tbody>' +
          d.list.map(function (o) {
            return '<tr>' +
              '<td>' + esc(o.order_sn) + '</td>' +
              '<td>' + esc(o.username || '-') + '</td>' +
              '<td>¥' + o.amount + '</td>' +
              '<td>' + o.points + '</td>' +
              '<td>' + (tMap[o.pay_type] || '-') + '</td>' +
              '<td>' + (sMap[o.status] || '-') + '</td>' +
              '<td>' + esc(o.paid_at || '-') + '</td>' +
              '<td>' + esc(o.created_at) + '</td></tr>';
          }).join('') + '</tbody></table></div>';
      }
      $('ordersPager').innerHTML = pager(d.page, d.pages, function (p) { loadOrders(p, $('orderStatus').value); });
    });
  }
  $('orderFilterBtn').addEventListener('click', function () { loadOrders(1, $('orderStatus').value); });

  /* ---------- 解析记录 ---------- */
  function loadLogs(page) {
    state.page = page || 1;
    adminApi('logs', { page: state.page }).then(function (res) {
      if (!checkAuth(res)) return;
      var d = res.data;
      if (!d.list.length) { $('logsTable').innerHTML = '<p style="color:#8a90a3">暂无数据</p>'; }
      else {
        $('logsTable').innerHTML =
          '<div class="table-wrap"><table><thead><tr><th>ID</th><th>用户</th><th>平台</th><th>内容</th><th>消耗</th><th>IP</th><th>时间</th></tr></thead><tbody>' +
          d.list.map(function (p) {
            return '<tr>' +
              '<td>' + p.id + '</td>' +
              '<td>' + esc(p.username || '-') + '</td>' +
              '<td>' + esc(p.platform || '-') + '</td>' +
              '<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(p.text) + '">' + esc(p.text) + '</td>' +
              '<td>' + p.cost + '</td>' +
              '<td>' + esc(p.ip || '-') + '</td>' +
              '<td>' + esc(p.created_at) + '</td></tr>';
          }).join('') + '</tbody></table></div>';
      }
      $('logsPager').innerHTML = pager(d.page, d.pages, function (p) { loadLogs(p); });
    });
  }

  /* ---------- 卡密 ---------- */
  function loadCards(page) {
    state.page = page || 1;
    adminApi('cards', { page: state.page }).then(function (res) {
      if (!checkAuth(res)) return;
      var d = res.data;
      if (!d.list.length) { $('cardsTable').innerHTML = '<p style="color:#8a90a3">暂无数据</p>'; }
      else {
        $('cardsTable').innerHTML =
          '<div class="table-wrap"><table><thead><tr><th>ID</th><th>卡密</th><th>点数</th><th>状态</th><th>使用者</th><th>使用时间</th><th>创建时间</th></tr></thead><tbody>' +
          d.list.map(function (c) {
            return '<tr>' +
              '<td>' + c.id + '</td>' +
              '<td><code>' + esc(c.card_no) + '</code></td>' +
              '<td>' + c.points + '</td>' +
              '<td>' + (c.status == 1 ? '<span class="badge-ok">已使用</span>' : '<span class="badge-wait">未使用</span>') + '</td>' +
              '<td>' + esc(c.username || '-') + '</td>' +
              '<td>' + esc(c.used_at || '-') + '</td>' +
              '<td>' + esc(c.created_at) + '</td></tr>';
          }).join('') + '</tbody></table></div>';
      }
      $('cardsPager').innerHTML = pager(d.page, d.pages, function (p) { loadCards(p); });
    });
  }

  $('genCardsBtn').addEventListener('click', function () {
    var count = parseInt($('genCount').value, 10) || 10;
    var points = parseInt($('genPoints').value, 10) || 10;
    if (count > 500) { toast('单次最多生成 500 张'); return; }
    adminApi('gen_cards', { count: count, points: points }).then(function (res) {
      if (!checkAuth(res)) return;
      toast('已生成 ' + res.data.count + ' 张卡密');
      alert('以下卡密请复制保存（每张 ' + res.data.points + ' 点）：\n\n' + res.data.cards.join('\n'));
      loadCards(1);
    });
  });

  /* ---------- 设置 ---------- */
  function loadSettings() {
    adminApi('settings').then(function (res) {
      if (!checkAuth(res)) return;
      var d = res.data;
      $('set_site_name').value = d.site_name;
      $('set_site_desc').value = d.site_desc;
      $('set_announcement').value = d.announcement;
      $('set_parse_cost').value = d.parse_cost;
      $('set_register_points').value = d.register_points;
      $('set_points_per_yuan').value = d.points_per_yuan;
      $('set_epay_enabled').value = d.epay_enabled;
      $('set_epay_pay_types').value = d.epay_pay_types;
      $('set_epay_api').value = d.epay_api;
      $('set_epay_pid').value = d.epay_pid;
      $('set_epay_public_key').value = d.epay_public_key;
      $('set_epay_private_key').value = d.epay_private_key;
      $('set_alipay_enabled').value = d.alipay_enabled;
      $('set_alipay_app_id').value = d.alipay_app_id;
      $('set_alipay_private_key').value = d.alipay_private_key;
      $('set_alipay_public_key').value = d.alipay_public_key;
      $('set_wechat_enabled').value = d.wechat_enabled;
      $('set_wechat_name').value = d.wechat_name;
      $('set_wechat_qrcode').value = d.wechat_qrcode;
      $('set_wechat_desc').value = d.wechat_desc;
      $('set_site_version').value = d.site_version;
      $('set_bark_enabled').value = d.bark_enabled;
      $('set_bark_server').value = d.bark_server;
      $('set_bark_key').value = d.bark_key;
      $('set_bark_notify_register').value = d.bark_notify_register;
      $('set_bark_notify_recharge').value = d.bark_notify_recharge;
    });
  }

  function collectSettings(keys) {
    var p = {};
    keys.forEach(function (k) { p[k] = $('set_' + k).value.trim(); });
    return p;
  }
  $('saveSettingsBtn').addEventListener('click', function () {
    adminApi('save_settings', collectSettings(
      ['site_name', 'site_desc', 'announcement', 'parse_cost', 'register_points', 'points_per_yuan', 'site_version']
    )).then(function (res) {
      if (!checkAuth(res)) return;
      if (res.code !== 0) { toast(res.msg); return; }
      toast('基础设置已保存');
    });
  });
  $('saveWechatBtn').addEventListener('click', function () {
    adminApi('save_settings', collectSettings(
      ['wechat_enabled', 'wechat_name', 'wechat_qrcode', 'wechat_desc']
    )).then(function (res) {
      if (!checkAuth(res)) return;
      if (res.code !== 0) { toast(res.msg); return; }
      toast('公众号设置已保存');
    });
  });
  $('saveEpayBtn').addEventListener('click', function () {
    adminApi('save_settings', collectSettings(
      ['epay_enabled', 'epay_pay_types', 'epay_api', 'epay_pid', 'epay_public_key', 'epay_private_key']
    )).then(function (res) {
      if (!checkAuth(res)) return;
      if (res.code !== 0) { toast(res.msg); return; }
      toast('支付配置已保存');
    });
  });
  $('saveAlipayBtn').addEventListener('click', function () {
    adminApi('save_settings', collectSettings(
      ['alipay_enabled', 'alipay_app_id', 'alipay_private_key', 'alipay_public_key']
    )).then(function (res) {
      if (!checkAuth(res)) return;
      if (res.code !== 0) { toast(res.msg); return; }
      toast('支付宝配置已保存');
    });
  });
  $('saveBarkBtn').addEventListener('click', function () {
    adminApi('save_settings', collectSettings(
      ['bark_enabled', 'bark_server', 'bark_key', 'bark_notify_register', 'bark_notify_recharge']
    )).then(function (res) {
      if (!checkAuth(res)) return;
      if (res.code !== 0) { toast(res.msg); return; }
      toast('Bark 配置已保存');
    });
  });

  /* ---------- 解析类型管理 ---------- */
  var parseTypesCache = [];
  var parseTypesDataCache = {};

  function parseTypeName(t) {
    if (!t) return '<span style="color:#b6bac9">自动识别</span>';
    for (var i = 0; i < parseTypesCache.length; i++) {
      if (parseTypesCache[i].key === t) return esc(parseTypesCache[i].name);
    }
    return esc(t);
  }

  function fillApiParseTypeOptions(selected) {
    var sel = $('apiParseType');
    sel.innerHTML = '<option value="">自动识别</option>';
    parseTypesCache.forEach(function (pt) {
      var opt = document.createElement('option');
      opt.value = pt.key;
      opt.textContent = pt.name;
      if (pt.key === selected) opt.selected = true;
      sel.appendChild(opt);
    });
  }

  function loadParseTypesCache() {
    adminApi('parse_types').then(function (res) {
      if (res.code === 0) parseTypesCache = res.data.list || [];
    });
  }

  function loadParseTypes() {
    adminApi('parse_types').then(function (res) {
      if (!checkAuth(res)) return;
      var d = res.data;
      parseTypesCache = d.list || [];
      d.list.forEach(function (pt) { parseTypesDataCache[pt.id] = pt; });
      if (!d.list.length) { $('parseTypesTable').innerHTML = '<p style="color:#8a90a3">暂无解析类型</p>'; }
      else {
        $('parseTypesTable').innerHTML =
          '<div class="table-wrap"><table><thead><tr><th>ID</th><th>类型名称</th><th>类型标识</th><th>排序</th><th>状态</th><th>操作</th></tr></thead><tbody>' +
          d.list.map(function (pt) {
            return '<tr>' +
              '<td>' + pt.id + '</td>' +
              '<td>' + esc(pt.name) + '</td>' +
              '<td><code>' + esc(pt.key) + '</code></td>' +
              '<td>' + pt.sort + '</td>' +
              '<td><label class="switch"><input type="checkbox" data-pt-toggle="' + pt.id + '"' + (pt.enabled == 1 ? ' checked' : '') + '><span class="slider"></span></label></td>' +
              '<td>' +
                '<button class="btn btn-sm" data-pt-edit="' + pt.id + '">编辑</button> ' +
                '<button class="btn btn-sm btn-danger" data-pt-del="' + pt.id + '">删除</button>' +
              '</td></tr>';
          }).join('') + '</tbody></table></div>';
        bindParseTypeActions();
      }
    });
  }

  function bindParseTypeActions() {
    document.querySelectorAll('[data-pt-toggle]').forEach(function (s) {
      s.addEventListener('change', function () {
        var id = s.getAttribute('data-pt-toggle');
        adminApi('parse_type_toggle', { id: id, enabled: s.checked ? 1 : 0 }).then(function (res) {
          if (!checkAuth(res)) return;
          toast(s.checked ? '已启用' : '已禁用');
          loadParseTypesCache();
        });
      });
    });
    document.querySelectorAll('[data-pt-edit]').forEach(function (b) {
      b.addEventListener('click', function () { openPtModal(b.getAttribute('data-pt-edit')); });
    });
    document.querySelectorAll('[data-pt-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('确定删除该解析类型吗？')) return;
        adminApi('parse_type_delete', { id: b.getAttribute('data-pt-del') }).then(function (res) {
          if (!checkAuth(res)) return;
          toast('已删除');
          loadParseTypes();
        });
      });
    });
  }

  function openPtModal(id) {
    $('ptModalTitle').textContent = id ? '编辑解析类型' : '新增解析类型';
    var pt = id ? parseTypesDataCache[id] : null;
    $('ptId').value = id || '';
    $('ptName').value = pt ? pt.name : '';
    $('ptKey').value = pt ? pt.key : '';
    setRadio('ptEnabled', pt ? pt.enabled : 1);
    $('ptSort').value = pt ? (pt.sort || 0) : '';
    $('ptModal').classList.remove('hide');
  }

  $('ptAddBtn').addEventListener('click', function () { openPtModal(null); });
  $('ptSaveBtn').addEventListener('click', function () {
    var id = $('ptId').value;
    var params = {
      id: id,
      name: $('ptName').value.trim(),
      key: $('ptKey').value.trim(),
      enabled: getRadio('ptEnabled'),
      sort: $('ptSort').value.trim()
    };
    adminApi('parse_type_save', params).then(function (res) {
      if (!checkAuth(res)) return;
      if (res.code !== 0) { toast(res.msg); return; }
      $('ptModal').classList.add('hide');
      toast('已保存');
      loadParseTypes();
    });
  });
  $('ptModal').addEventListener('click', function (e) { if (e.target === this) this.classList.add('hide'); });

  /* ---------- 版本管理 ---------- */
  var versionsDataCache = {};

  function verTypeName(t) {
    return { update: '更新', optimize: '优化', fix: '修复' }[t] || '更新';
  }

  function loadVersions() {
    adminApi('versions').then(function (res) {
      if (!checkAuth(res)) return;
      var d = res.data;
      versionsDataCache = {};
      d.list.forEach(function (v) { versionsDataCache[v.id] = v; });
      if (!d.list.length) { $('versionsTable').innerHTML = '<p style="color:#8a90a3">暂无版本记录</p>'; }
      else {
        $('versionsTable').innerHTML =
          '<div class="table-wrap"><table><thead><tr><th>ID</th><th>版本号</th><th>类型</th><th>标题</th><th>时间</th><th>操作</th></tr></thead><tbody>' +
          d.list.map(function (v) {
            return '<tr>' +
              '<td>' + v.id + '</td>' +
              '<td><code>' + esc(v.version) + '</code></td>' +
              '<td>' + esc(verTypeName(v.type)) + '</td>' +
              '<td>' + esc(v.title) + '</td>' +
              '<td>' + esc(v.created_at) + '</td>' +
              '<td>' +
                '<button class="btn btn-sm" data-ver-edit="' + v.id + '">编辑</button> ' +
                '<button class="btn btn-sm btn-danger" data-ver-del="' + v.id + '">删除</button>' +
              '</td></tr>';
          }).join('') + '</tbody></table></div>';
        bindVersionActions();
      }
    });
  }

  function bindVersionActions() {
    document.querySelectorAll('[data-ver-edit]').forEach(function (b) {
      b.addEventListener('click', function () { openVerModal(b.getAttribute('data-ver-edit')); });
    });
    document.querySelectorAll('[data-ver-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('确定删除该版本记录吗？')) return;
        adminApi('version_delete', { id: b.getAttribute('data-ver-del') }).then(function (res) {
          if (!checkAuth(res)) return;
          toast('已删除');
          loadVersions();
        });
      });
    });
  }

  function openVerModal(id) {
    $('verModalTitle').textContent = id ? '编辑版本' : '新增版本';
    var v = id ? versionsDataCache[id] : null;
    $('verId').value = id || '';
    $('verVersion').value = v ? v.version : '';
    $('verType').value = v ? v.type : 'update';
    $('verTitle').value = v ? v.title : '';
    $('verContent').value = v ? v.content : '';
    $('verModal').classList.remove('hide');
  }

  $('verAddBtn').addEventListener('click', function () { openVerModal(null); });
  $('verSaveBtn').addEventListener('click', function () {
    var id = $('verId').value;
    var params = {
      id: id,
      version: $('verVersion').value.trim(),
      type: $('verType').value,
      title: $('verTitle').value.trim(),
      content: $('verContent').value.trim()
    };
    adminApi('version_save', params).then(function (res) {
      if (!checkAuth(res)) return;
      if (res.code !== 0) { toast(res.msg); return; }
      $('verModal').classList.add('hide');
      toast('已保存');
      loadVersions();
    });
  });
  $('verModal').addEventListener('click', function (e) { if (e.target === this) this.classList.add('hide'); });

  /* ---------- 接口管理 ---------- */
  function loadApis(page, q) {
    state.page = page || 1;
    adminApi('apis', { page: state.page, q: q || $('apiSearch').value.trim() })
      .then(function (res) {
        if (!checkAuth(res)) return;
        var d = res.data;
        if (!d.list.length) { $('apisTable').innerHTML = '<p style="color:#8a90a3">暂无数据</p>'; }
        else {
          d.list.forEach(function (a) { apiDataCache[a.id] = a; });
          $('apisTable').innerHTML =
            '<div class="table-wrap"><table><thead><tr><th>接口ID</th><th>接口名称</th><th>接口图片</th><th>接口标识</th><th>解析类型</th><th>接口状态</th><th>操作</th></tr></thead><tbody>' +
            d.list.map(function (a) {
              var icon = a.icon
                ? '<img class="api-icon" src="' + esc(a.icon) + '" alt="">'
                : '<span class="api-icon"></span>';
              return '<tr>' +
                '<td>' + a.id + '</td>' +
                '<td>' + esc(a.name) + '</td>' +
                '<td>' + icon + '</td>' +
                '<td><code>' + esc(a.slug) + '</code></td>' +
                '<td>' + (parseTypeName(a.parse_type)) + '</td>' +
                '<td><label class="switch"><input type="checkbox" data-api-toggle="' + a.id + '"' + (a.enabled == 1 ? ' checked' : '') + '><span class="slider"></span></label></td>' +
                '<td>' +
                  '<button class="btn btn-sm" data-api-edit="' + a.id + '">编辑</button> ' +
                  '<button class="btn btn-sm btn-danger" data-api-del="' + a.id + '">删除</button>' +
                '</td></tr>';
            }).join('') + '</tbody></table></div>';
          bindApiActions();
        }
        $('apisPager').innerHTML = pager(d.page, d.pages, function (p) { loadApis(p); });
      });
  }

  function bindApiActions() {
    document.querySelectorAll('[data-api-toggle]').forEach(function (s) {
      s.addEventListener('change', function () {
        var id = s.getAttribute('data-api-toggle');
        adminApi('api_toggle', { id: id, enabled: s.checked ? 1 : 0 }).then(function (res) {
          if (!checkAuth(res)) return;
          toast(s.checked ? '已启用' : '已禁用');
        });
      });
    });
    document.querySelectorAll('[data-api-edit]').forEach(function (b) {
      b.addEventListener('click', function () { openApiModal(b.getAttribute('data-api-edit')); });
    });
    document.querySelectorAll('[data-api-del]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('确定删除该接口吗？')) return;
        adminApi('api_delete', { id: b.getAttribute('data-api-del') }).then(function (res) {
          if (!checkAuth(res)) return;
          toast('已删除');
          loadApis(state.page);
        });
      });
    });
  }

  var apiDataCache = {};
  function openApiModal(id) {
    $('apiModalTitle').textContent = id ? '编辑接口' : '新增接口';
    var a = id ? apiDataCache[id] : null;
    $('apiId').value = id || '';
    $('apiName').value = a ? a.name : '';
    $('apiSlug').value = a ? a.slug : '';
    $('apiIcon').value = a ? a.icon : '';
    setRadio('apiEnabled', a ? a.enabled : 1);
    $('apiSort').value = a ? (a.sort || 0) : '';
    $('apiUrl').value = a ? (a.api_url || '') : '';
    $('apiRespData').value = a ? (a.resp_data || '') : '';
    $('apiRespTitle').value = a ? (a.resp_title || '') : '';
    $('apiRespVideo').value = a ? (a.resp_video || '') : '';
    $('apiRespImg').value = a ? (a.resp_img || '') : '';
    fillApiParseTypeOptions(a ? (a.parse_type || '') : '');
    $('apiModal').classList.remove('hide');
  }

  function setRadio(name, val) {
    document.querySelectorAll('input[name="' + name + '"]').forEach(function (r) {
      r.checked = (r.value == val);
    });
  }
  function getRadio(name) {
    var el = document.querySelector('input[name="' + name + '"]:checked');
    return el ? el.value : '0';
  }

  $('apiAddBtn').addEventListener('click', function () { openApiModal(null); });
  $('apiSearchBtn').addEventListener('click', function () { loadApis(1); });
  $('apiSearch').addEventListener('keydown', function (e) { if (e.key === 'Enter') loadApis(1); });

  $('apiSaveBtn').addEventListener('click', function () {
    var id = $('apiId').value;
    var params = {
      id: id,
      name: $('apiName').value.trim(),
      slug: $('apiSlug').value.trim(),
      icon: $('apiIcon').value.trim(),
      enabled: getRadio('apiEnabled'),
      api_url: $('apiUrl').value.trim(),
      resp_data: $('apiRespData').value.trim(),
      resp_title: $('apiRespTitle').value.trim(),
      resp_video: $('apiRespVideo').value.trim(),
      resp_img: $('apiRespImg').value.trim(),
      parse_type: $('apiParseType').value,
      sort: $('apiSort').value.trim()
    };
    adminApi('api_save', params).then(function (res) {
      if (!checkAuth(res)) return;
      if (res.code !== 0) { toast(res.msg); return; }
      $('apiModal').classList.add('hide');
      toast('接口已保存');
      if ($('apiSearch').value.trim()) $('apiSearch').value = '';
      loadApis(1);
    });
  });

  $('apiModal').addEventListener('click', function (e) { if (e.target === this) this.classList.add('hide'); });

  // 预加载接口数据用于编辑回填
  adminApi('apis', { page: 1 }).then(function (res) {
    if (res.code === 0) {
      (res.data.list || []).forEach(function (a) { apiDataCache[a.id] = a; });
    }
  });

  /* ---------- 分页 ---------- */
  function pager(page, pages, cb) {
    if (pages <= 1) return '';
    var btns = [];
    var push = function (label, p) {
      if (p < 1 || p > pages) return;
      btns.push('<button class="btn btn-sm" data-p="' + p + '"' + (p === page ? ' style="background:#6d5bff;color:#fff"' : '') + '>' + label + '</button>');
    };
    push('上一页', page - 1);
    var s = Math.max(1, page - 2), e = Math.min(pages, page + 2);
    for (var i = s; i <= e; i++) push(i, i);
    push('下一页', page + 1);
    var html = btns.join(' ');
    return '<span>' + page + ' / ' + pages + '</span> ' + html;
  }

  document.addEventListener('click', function (e) {
    var pBtn = e.target.closest('[data-p]');
    if (pBtn) {
      var page = parseInt(pBtn.getAttribute('data-p'), 10);
      var activePage = document.querySelector('.nav-item.active');
      var name = activePage ? activePage.getAttribute('data-page') : '';
      if (name === 'users') loadUsers(page);
      if (name === 'orders') loadOrders(page, $('orderStatus').value);
      if (name === 'logs') loadLogs(page);
      if (name === 'cards') loadCards(page);
      if (name === 'apis') loadApis(page);
    }
  });

  adminApi('me').then(function (res) {
    if (res.code === 0) {
      loggedIn = true;
    }
    renderAuth();
  }).catch(function () {
    renderAuth();
  });
})();
