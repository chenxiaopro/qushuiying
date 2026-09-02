/* 前台单页交互 */
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var toastTimer = null;

  function toast(msg) {
    var t = $('toast');
    t.textContent = msg;
    t.classList.remove('hide');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.add('hide'); }, 2600);
  }

  function showModal(id) { $(id).classList.remove('hide'); }
  function hideModal(id) { $(id).classList.add('hide'); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function api(action, params, method) {
    method = method || 'POST';
    var q = new URLSearchParams();
    q.append('action', action);
    if (method !== 'GET' && method !== 'HEAD' && window.WM.csrf) {
      q.append('csrf', window.WM.csrf);
    }
    Object.keys(params || {}).forEach(function (k) { q.append(k, params[k]); });
    var url = method === 'GET' || method === 'HEAD' ? ('api.php?' + q.toString()) : 'api.php';
    var opts = { method: method, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } };
    if (method !== 'GET' && method !== 'HEAD') opts.body = q.toString();
    return fetch(url, opts).then(function (r) { return r.json(); }).then(function (res) {
      if (res && res.data && res.data.csrf) window.WM.csrf = res.data.csrf;
      return res;
    });
  }

  /* ---------- 用户状态 ---------- */
  var loggedIn = false;
  var points = 0;

  function refreshMe() {
    return api('me', {}, 'GET').then(function (res) {
      if (res.code === 0 && res.data && res.data.logged_in) {
        loggedIn = true;
        points = res.data.points;
        $('username').dataset.name = res.data.username;
        renderUser();
      } else {
        loggedIn = false;
        renderUser();
      }
      return res;
    }).catch(function () { loggedIn = false; renderUser(); });
  }

  function renderUser() {
    var showLogged = !loggedIn ? 'add' : 'remove';
    ['balance', 'username', 'btnRecharge', 'btnLogout'].forEach(function (id) {
      $(id).classList[showLogged]('hide');
    });
    ['btnLogin', 'btnRegister'].forEach(function (id) {
      $(id).classList[loggedIn ? 'add' : 'remove']('hide');
    });
    if (loggedIn) {
      $('username').textContent = '欢迎回来：' + ($('username').dataset.name || '');
      $('balance').textContent = '余额：' + points + ' 点';
    }
  }

  /* ---------- 弹窗切换 ---------- */
  ['btnLogin', 'btnRegister', 'btnRecharge'].forEach(function (id) {
    $(id).addEventListener('click', function () {
      if (id === 'btnLogin') showModal('loginModal');
      if (id === 'btnRegister') showModal('registerModal');
      if (id === 'btnRecharge') { openRecharge(); }
    });
  });

  $('btnLogout').addEventListener('click', function () {
    api('logout').then(function () { loggedIn = false; renderUser(); toast('已退出'); });
  });

  /* 弹窗内切换登录/注册 */
  document.querySelectorAll('[data-switch]').forEach(function (el) {
    el.addEventListener('click', function () {
      var target = el.getAttribute('data-switch');
      hideModal('loginModal');
      hideModal('registerModal');
      showModal(target === 'login' ? 'loginModal' : 'registerModal');
    });
  });

  /* 点击遮罩关闭 */
  document.querySelectorAll('.modal-mask').forEach(function (m) {
    m.addEventListener('click', function (e) { if (e.target === m) m.classList.add('hide'); });
  });

  /* ---------- 登录 / 注册 ---------- */
  $('loginSubmit').addEventListener('click', function () {
    var btn = $('loginSubmit');
    if (btn.disabled) return;
    btn.disabled = true;
    api('login', {
      username: $('loginUser').value.trim(),
      password: $('loginPass').value,
      remember: $('loginRemember').checked ? '1' : '0'
    })
      .then(function (res) {
        if (res.code === 0) {
          hideModal('loginModal');
          points = res.data.points;
          $('username').dataset.name = res.data.username;
          loggedIn = true;
          renderUser();
          toast('登录成功');
        } else { toast(res.msg); }
      })
      .then(function () { btn.disabled = false; });
  });

  $('regSubmit').addEventListener('click', function () {
    var btn = $('regSubmit');
    if (btn.disabled) return;
    btn.disabled = true;
    api('register', { username: $('regUser').value.trim(), password: $('regPass').value })
      .then(function (res) {
        if (res.code === 0) {
          toast(res.data.msg);
          $('loginUser').value = $('regUser').value.trim();
          hideModal('registerModal');
          showModal('loginModal');
        } else { toast(res.msg); }
      })
      .then(function () { btn.disabled = false; });
  });

  $('loginPass').addEventListener('keydown', function (e) { if (e.key === 'Enter') $('loginSubmit').click(); });
  $('regPass').addEventListener('keydown', function (e) { if (e.key === 'Enter') $('regSubmit').click(); });

  /* ---------- 解析类型选项 ---------- */
  function loadParseTypes() {
    api('parse_types', {}, 'GET').then(function (res) {
      if (res.code !== 0) return;
      var sel = $('parseType');
      if (!sel) return;
      sel.innerHTML = '<option value="">自动识别</option>';
      (res.data.list || []).forEach(function (pt) {
        var opt = document.createElement('option');
        opt.value = pt.key;
        opt.textContent = pt.name;
        sel.appendChild(opt);
      });
    });
  }

  /* ---------- 解析 ---------- */
  $('btnParse').addEventListener('click', doParse);
  $('txt').addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) doParse(); });

  if ($('btnPaste')) {
    $('btnPaste').addEventListener('click', function () {
      if (!navigator.clipboard || !navigator.clipboard.readText) {
        toast('当前浏览器不支持一键粘贴，请手动粘贴');
        $('txt').focus();
        return;
      }
      navigator.clipboard.readText().then(function (t) {
        if (!t) { toast('剪贴板为空'); return; }
        $('txt').value = t.trim();
        toast('已粘贴');
      }).catch(function () {
        toast('无法读取剪贴板，请手动粘贴');
        $('txt').focus();
      });
    });
  }
  if ($('btnClear')) {
    $('btnClear').addEventListener('click', function () {
      $('txt').value = '';
      $('txt').focus();
    });
  }

  function doParse() {
    var text = $('txt').value.trim();
    if (!text) { toast('请先粘贴分享内容'); return; }
    if (!loggedIn) { toast('请先登录'); showModal('loginModal'); return; }
    var btn = $('btnParse');
    btn.disabled = true;
    btn.textContent = '解析中...';
    var params = { text: text };
    if ($('parseType') && $('parseType').value) params.mode = $('parseType').value;
    api('parse', params).then(function (res) {
      if (res.code === 0) {
        renderResult(res.data);
        points = res.data.points_left;
        $('balance').textContent = '余额：' + points + ' 点';
        toast('解析成功，消耗 ' + res.data.cost + ' 点');
      } else {
        toast(res.msg);
        if (res.code === 401) { loggedIn = false; renderUser(); }
      }
    }).catch(function () { toast('网络异常，请重试'); })
      .then(function () {
        btn.disabled = false;
        btn.textContent = '立即解析';
      });
  }

  function dlProxy(url, name) {
    return 'download.php?url=' + encodeURIComponent(url) + '&name=' + encodeURIComponent(name || '');
  }

  function streamProxy(url) {
    return 'download.php?url=' + encodeURIComponent(url) + '&stream=1';
  }

  function normalizeText(s) {
    return String(s == null ? '' : s).replace(/^#+/g, '').trim();
  }

  function imgExt(src) {
    var m = /\.(jpe?g|png|gif|webp|bmp)(\?|$)/i.exec(String(src || ''));
    return m ? m[1].toLowerCase() : 'jpg';
  }

  var currentImages = [];
  var currentBaseName = 'download';

  function renderResult(d) {
    var r = d.result;
    var list = Array.isArray(r.list) ? r.list : [];
    if (list.length > 0) {
      renderList(r, list);
      return;
    }
    $('resultCard').classList.remove('hide');
    $('resultListWrap').classList.add('hide');
    var v = $('videoPreview');
    var imgWrap = $('imagesWrap');
    var type = parseInt(r.type, 10) || 1;
    var images = Array.isArray(r.images) ? r.images.filter(Boolean) : [];
    var live = Array.isArray(r.live) ? r.live.filter(Boolean) : [];
    var videoUrl = String(r.video_url || '') || (live.length ? live[0] : '');
    var isImages = (type === 2 || type === 3) && images.length > 0 && !videoUrl;
    var baseName = (r.title || r.platform_name || 'download').replace(/[\/\\:*?"<>|]/g, '_');
    currentImages = isImages ? images.slice() : [];
    currentBaseName = baseName;

    $('platformBadge').textContent = r.platform_name || '';

    var title = String(r.title || '').trim();
    $('resultTitle').textContent = title || '无标题';
    $('resultTitle').title = title;

    // 作者
    var author = r.author;
    var authorEl = $('resultAuthor');
    if (author && (author.nickname || author.name)) {
      authorEl.innerHTML =
        (author.avatar ? '<img src="' + esc(author.avatar) + '" alt="">' : '') +
        '<span>' + esc(author.nickname || author.name) + '</span>';
      authorEl.classList.remove('hide');
    } else {
      authorEl.innerHTML = '';
      authorEl.classList.add('hide');
    }

    // 描述：与标题重复时隐藏，避免重复显示
    var desc = String(r.desc || '').trim();
    var showDesc = desc !== '' && normalizeText(desc) !== normalizeText(title);
    var descEl = $('resultDesc');
    descEl.textContent = showDesc ? desc : '';
    descEl.classList.toggle('hide', !showDesc);

    // 背景音乐
    var music = r.music || {};
    var musicEl = $('resultMusic');
    var btnMusic = $('btnDownloadMusic');
    if (music && music.url) {
      btnMusic.href = dlProxy(music.url, (music.title || baseName) + '.mp3');
      btnMusic.classList.remove('hide');
      musicEl.textContent = '背景音乐 · ' + (music.title || music.author || '');
      musicEl.classList.remove('hide');
    } else {
      btnMusic.removeAttribute('href');
      btnMusic.classList.add('hide');
      musicEl.textContent = '';
      musicEl.classList.add('hide');
    }

    var btnDownload = $('btnDownload');
    var btnBackup = $('btnDownloadBackup');
    var btnLive = $('btnDownloadLive');
    var btnAll = $('btnDownloadAll');
    var card = $('resultCard');

    if (isImages) {
      // 图集/实况：图片在上，信息在下
      card.classList.add('is-images');
      v.classList.add('hide');
      v.removeAttribute('src');
      v.removeAttribute('poster');
      var liveWrap = $('liveWrap');
      if (liveWrap) liveWrap.classList.add('hide');
      imgWrap.innerHTML = images.map(function (src, i) {
        var ext = imgExt(src);
        return '<div class="img-item">' +
          '<img src="' + esc(src) + '" alt="图片 ' + (i + 1) + '" loading="lazy" onclick="window.open(this.src)">' +
          '<a class="img-download" href="' + dlProxy(src, baseName + '-' + (i + 1) + '.' + ext) + '">下载</a>' +
          '</div>';
      }).join('');
      imgWrap.classList.remove('hide');
      btnDownload.classList.add('hide');
      btnDownload.removeAttribute('href');
    } else {
      // 视频：信息在上，视频在下
      card.classList.remove('is-images');
      imgWrap.innerHTML = '';
      imgWrap.classList.add('hide');
      v.classList.remove('hide');
      v.poster = r.cover || (images.length ? images[0] : '');
      if (videoUrl) {
        v.src = streamProxy(videoUrl);
        btnDownload.href = dlProxy(videoUrl, baseName + '.mp4');
        btnDownload.textContent = '下载无水印视频';
        btnDownload.classList.remove('hide');
      } else {
        v.removeAttribute('src');
        btnDownload.removeAttribute('href');
        btnDownload.textContent = '暂无视频地址';
      }
    }

    // 备用视频下载
    if (!isImages && r.video_backup && r.video_backup.length) {
      btnBackup.href = dlProxy(r.video_backup[0], baseName + '-备用.mp4');
      btnBackup.classList.remove('hide');
    } else {
      btnBackup.removeAttribute('href');
      btnBackup.classList.add('hide');
    }

    // 实况视频下载（抖音 Live Photo 等）
    if (live.length && live[0] !== videoUrl) {
      btnLive.href = dlProxy(live[0], baseName + '-实况.mp4');
      btnLive.classList.remove('hide');
    } else {
      btnLive.removeAttribute('href');
      btnLive.classList.add('hide');
    }

    // 全部下载（图集多张时）
    if (isImages && images.length > 1) {
      btnAll.classList.remove('hide');
    } else {
      btnAll.classList.add('hide');
    }

    $('resultSection').hidden = false;
    $('resultSection').scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function batchDownload(urls, name) {
    fetch('download.php?batch=1', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: name, urls: urls })
    }).then(function (res) {
      if (!res.ok) { throw new Error('HTTP ' + res.status); }
      return res.blob();
    }).then(function (blob) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = name + '.zip';
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
      toast('已开始下载');
    }).catch(function () { toast('打包下载失败，请重试'); });
  }

  function renderList(r, list) {
    $('resultCard').classList.add('hide');
    $('resultListWrap').classList.remove('hide');
    $('listPlatformBadge').textContent = r.platform_name || r.platform || '';
    $('listCount').textContent = '共 ' + list.length + ' 个作品';

    var grid = $('resultListGrid');
    grid.innerHTML = list.map(function (it, i) {
      var cover = it.cover || (it.images && it.images.length ? it.images[0] : '');
      var title = String(it.title || '').trim() || '作品 ' + (i + 1);
      var author = it.author && (it.author.nickname || it.author.name) ? esc(it.author.nickname || it.author.name) : '';
      var type = parseInt(it.type, 10) || 1;
      var isImages = type === 2 && it.images && it.images.length > 0;
      var baseName = title.replace(/[\/\\:*?"<>|]/g, '_') || ('作品-' + (i + 1));

      var coverHtml = cover
        ? '<img src="' + esc(cover) + '" alt="" loading="lazy" onclick="window.open(this.src)">'
        : '<div class="list-cover-empty">无封面</div>';

      var actions = '';
      if (isImages && it.images.length > 1) {
        actions += '<button class="btn btn-primary btn-sm list-btn" data-batch="' + i + '">打包下载图集</button>';
      } else if (it.video_url) {
        actions += '<a class="btn btn-primary btn-sm list-btn" href="' + dlProxy(it.video_url, baseName + '.mp4') + '">下载视频</a>';
      } else if (it.images && it.images.length) {
        actions += '<a class="btn btn-primary btn-sm list-btn" href="' + dlProxy(it.images[0], baseName + '.jpg') + '">下载图片</a>';
      }

      return '<div class="list-item">' +
        '<div class="list-cover">' + coverHtml + '</div>' +
        '<div class="list-info">' +
          '<div class="list-title" title="' + esc(title) + '">' + esc(title) + '</div>' +
          (author ? '<div class="list-author">' + author + '</div>' : '') +
        '</div>' +
        '<div class="list-actions">' + actions + '</div>' +
        '</div>';
    }).join('');

    grid.querySelectorAll('[data-batch]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var idx = parseInt(this.getAttribute('data-batch'), 10);
        var it = list[idx];
        if (!it || !it.images || !it.images.length) return;
        batchDownload(it.images, String(it.title || '图集').replace(/[\/\\:*?"<>|]/g, '_'));
      });
    });

    $('resultSection').hidden = false;
    $('resultSection').scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  /* ---------- 全部下载（图集批量打包） ---------- */
  var downloadingAll = false;
  $('btnDownloadAll').addEventListener('click', function (e) {
    e.preventDefault();
    if (downloadingAll) return;
    if (!currentImages.length) { toast('暂无可下载的图片'); return; }
    downloadingAll = true;
    var btn = this;
    var original = btn.textContent;
    btn.textContent = '打包中...';
    fetch('download.php?batch=1', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: currentBaseName, urls: currentImages })
    }).then(function (res) {
      if (!res.ok) { throw new Error('HTTP ' + res.status); }
      return res.blob();
    }).then(function (blob) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = currentBaseName + '.zip';
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
      toast('已开始下载');
    }).catch(function () { toast('打包下载失败，请重试'); })
      .then(function () {
        downloadingAll = false;
        btn.textContent = original;
      });
  });

  /* ---------- 充值 ---------- */
  var selectedPoints = 0;

  function openRecharge() {
    if (!loggedIn) { toast('请先登录'); showModal('loginModal'); return; }
    showModal('rechargeModal');
    if (window.WM.payEnabled || window.WM.alipayEnabled) renderPointsGrid();
  }

  /* 充值面板切换（只绑定一次） */
  var tabsBound = false;
  function bindRechargeTabs() {
    if (tabsBound) return;
    tabsBound = true;
    var tabs = document.querySelectorAll('.recharge-tabs .tab');
    tabs.forEach(function (t) {
      t.addEventListener('click', function () {
        tabs.forEach(function (x) { x.classList.remove('active'); });
        t.classList.add('active');
        document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.add('hide'); });
        var panel = $('panel-' + t.getAttribute('data-tab'));
        if (panel) panel.classList.remove('hide');
      });
    });
  }
  bindRechargeTabs();

  function renderPointsGrid() {
    var grid = $('pointsGrid');
    if (!grid) return;
    var opts = [10, 30, 50, 100, 200, 500];
    var per = window.WM.pointsPerYuan || 10;
    grid.innerHTML = '';
    opts.forEach(function (p) {
      var div = document.createElement('div');
      div.className = 'point-item' + (p === selectedPoints ? ' active' : '');
      div.innerHTML = '<b>' + p + '</b><span>' + (p / per) + ' 元</span>';
      div.addEventListener('click', function () {
        selectedPoints = p;
        grid.querySelectorAll('.point-item').forEach(function (x) { x.classList.remove('active'); });
        div.classList.add('active');
      });
      grid.appendChild(div);
    });
  }

  var f2fPollTimer = null;
  function stopF2fPoll() {
    if (f2fPollTimer) { clearInterval(f2fPollTimer); f2fPollTimer = null; }
    if ($('f2fQrWrap')) $('f2fQrWrap').classList.add('hide');
  }

  if ($('paySubmit')) $('paySubmit').addEventListener('click', function () {
    if (selectedPoints <= 0) { toast('请选择充值点数'); return; }
    var typeEl = document.querySelector('input[name="payType"]:checked');
    var type = typeEl ? typeEl.value : 'alipay';
    stopF2fPoll();
    api('pay_create', { points: selectedPoints, type: type }).then(function (res) {
      if (res.code !== 0) { toast(res.msg); return; }
      if (res.data.qr_code) {
        // 支付宝当面付：展示二维码并轮询支付结果
        $('f2fQrWrap').classList.remove('hide');
        $('f2fQrStatus').textContent = '等待支付...';
        $('f2fQrImg').src = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=' + encodeURIComponent(res.data.qr_code);
        f2fPollTimer = setInterval(function () {
          api('pay_query', { order_sn: res.data.order_sn }).then(function (r) {
            if (r.code === 0 && r.data.status === 1) {
              stopF2fPoll();
              $('f2fQrStatus').textContent = '支付成功，点数已到账';
              refreshMe();
            }
          });
        }, 2000);
      } else {
        showModal('payLoadingModal');
        window.location.href = res.data.pay_url;
      }
    });
  });

  $('cardSubmit').addEventListener('click', function () {
    var card = $('cardInput').value.trim();
    if (!card) { toast('请输入卡密'); return; }
    api('recharge_card', { card: card }).then(function (res) {
      if (res.code === 0) {
        points = res.data.points_left;
        $('balance').textContent = '余额：' + points + ' 点';
        $('cardInput').value = '';
        toast('充值成功，到账 ' + res.data.points + ' 点');
      } else { toast(res.msg); }
    });
  });

  /* 支付成功跳回提示 */
  var paid = new URLSearchParams(location.search).get('paid');
  if (paid) {
    refreshMe().then(function () {
      toast('支付成功，点数已到账');
      history.replaceState({}, '', location.pathname);
    });
  }

  /* ---------- 公告弹窗 + 公众号引导弹窗（顺序显示） ---------- */
  var announcementModal = $('announcementModal');
  var wechatModal = $('wechatModal');

  var ANN_KEY = 'wm_announcement_read';
  var WX_KEY = 'wm_wechat_closed';

  function storageGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function storageSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

  function announcementUnread() {
    var a = window.WM.announcement || '';
    return a !== '' && storageGet(ANN_KEY) !== a;
  }
  function wechatUnclosed() {
    var v = storageGet(WX_KEY);
    if (!v) return true;
    return (Date.now() - parseInt(v, 10)) >= 7 * 24 * 60 * 60 * 1000;
  }

  function closeAnnouncementModal() {
    hideModal('announcementModal');
    storageSet(ANN_KEY, window.WM.announcement || '');
    if (wechatModal && wechatUnclosed()) {
      setTimeout(function () { showModal('wechatModal'); }, 200);
    }
  }
  function closeWechatModal() {
    hideModal('wechatModal');
    storageSet(WX_KEY, String(Date.now()));
  }

  if (announcementModal) {
    $('announcementCloseBtn').addEventListener('click', closeAnnouncementModal);
    announcementModal.addEventListener('click', function (e) { if (e.target === this) closeAnnouncementModal(); });
  }
  if (wechatModal) {
    $('wechatCloseBtn').addEventListener('click', closeWechatModal);
    wechatModal.addEventListener('click', function (e) { if (e.target === this) closeWechatModal(); });
  }

  if (announcementModal && announcementUnread()) {
    setTimeout(function () { showModal('announcementModal'); }, 300);
  } else if (wechatModal && wechatUnclosed()) {
    setTimeout(function () { showModal('wechatModal'); }, 300);
  }

  refreshMe();
  loadParseTypes();
})();
