<?php
/**
 * 后台管理单页
 */
require_once __DIR__ . '/../../app/init.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>后台管理 - <?= htmlspecialchars(setting('site_name', '短视频去水印')) ?></title>
<link rel="stylesheet" href="../assets/css/admin.css?v=20260902">
</head>
<body>

<!-- 登录 -->
<div id="loginView" class="login-wrap">
  <div class="login-box">
    <h2>后台管理登录</h2>
    <input class="field" id="adminUser" placeholder="管理员账号" maxlength="20">
    <input class="field" id="adminPass" type="password" placeholder="密码" maxlength="32">
    <button class="btn btn-primary btn-block" id="adminLoginBtn">登 录</button>
  </div>
</div>

<!-- 主界面 -->
<div id="appView" class="layout hide">
  <aside class="sidebar">
    <div class="brand"><span class="brand-logo"></span>后台管理</div>
    <nav class="nav-group">
      <div class="nav-title">仪表盘</div>
      <div class="nav-item active" data-page="dashboard">仪表盘</div>
    </nav>
    <nav class="nav-group">
      <div class="nav-title">功能</div>
      <div class="nav-item" data-page="parseTypes">解析类型</div>
      <div class="nav-item" data-page="apis">接口管理</div>
      <div class="nav-item" data-page="cards">卡密管理</div>
    </nav>
    <nav class="nav-group">
      <div class="nav-title">管理</div>
      <div class="nav-item" data-page="users">用户管理</div>
      <div class="nav-item" data-page="orders">订单管理</div>
      <div class="nav-item" data-page="logs">解析记录</div>
    </nav>
    <nav class="nav-group">
      <div class="nav-title">系统</div>
      <div class="nav-item" data-page="versions">历史版本</div>
      <div class="nav-item" data-page="settings">站点设置</div>
    </nav>
    <div class="logout" id="adminLogout">退出登录</div>
  </aside>

  <main class="main">
    <!-- 仪表盘 -->
    <div class="page" data-name="dashboard">
      <div class="page-title">仪表盘</div>
      <div class="stats-grid" id="statsGrid"></div>
      <div class="card" style="margin-top:16px">
        <div class="page-sub" style="margin-bottom:8px">最近解析记录</div>
        <div id="recentParses"></div>
      </div>
    </div>

    <!-- 解析类型 -->
    <div class="page hide" data-name="parseTypes">
      <div class="page-head">
        <div>
          <div class="page-title" style="margin-bottom:4px">解析类型</div>
          <div class="page-sub">管理前台解析方式选项，可增删，与接口的「解析类型」对应</div>
        </div>
        <button class="btn btn-primary" id="ptAddBtn">新增解析类型</button>
      </div>
      <div class="card">
        <div id="parseTypesTable"></div>
      </div>
    </div>

    <!-- 接口管理 -->
    <div class="page hide" data-name="apis">
      <div class="page-head">
        <div>
          <div class="page-title" style="margin-bottom:4px">接口管理</div>
          <div class="page-sub">在此管理您的解析接口</div>
        </div>
        <button class="btn btn-primary" id="apiAddBtn">新增接口</button>
      </div>
      <div class="card">
        <div class="toolbar">
          <input class="field" id="apiSearch" placeholder="搜索接口名称 / 标识">
          <button class="btn btn-primary" id="apiSearchBtn">搜索</button>
        </div>
        <div id="apisTable"></div>
        <div class="pager" id="apisPager"></div>
      </div>
    </div>

    <!-- 用户管理 -->
    <div class="page hide" data-name="users">
      <div class="page-title">用户管理</div>
      <div class="card">
        <div class="toolbar">
          <input class="field" id="userSearch" placeholder="搜索用户名">
          <button class="btn btn-primary" id="userSearchBtn">搜索</button>
        </div>
        <div id="usersTable"></div>
        <div class="pager" id="usersPager"></div>
      </div>
    </div>

    <!-- 订单管理 -->
    <div class="page hide" data-name="orders">
      <div class="page-title">订单管理</div>
      <div class="card">
        <div class="toolbar">
          <select class="field" id="orderStatus" style="width:140px">
            <option value="">全部状态</option>
            <option value="0">待支付</option>
            <option value="1">已支付</option>
            <option value="2">已关闭</option>
          </select>
          <button class="btn btn-primary" id="orderFilterBtn">筛选</button>
        </div>
        <div id="ordersTable"></div>
        <div class="pager" id="ordersPager"></div>
      </div>
    </div>

    <!-- 解析记录 -->
    <div class="page hide" data-name="logs">
      <div class="page-title">解析记录</div>
      <div class="card">
        <div id="logsTable"></div>
        <div class="pager" id="logsPager"></div>
      </div>
    </div>

    <!-- 卡密管理 -->
    <div class="page hide" data-name="cards">
      <div class="page-title">卡密管理</div>
      <div class="card">
        <div class="toolbar">
          <input class="field" id="genCount" type="number" value="10" min="1" max="500" style="width:90px" title="生成数量">
          <input class="field" id="genPoints" type="number" value="10" min="1" style="width:120px" title="每张卡点数">
          <button class="btn btn-primary" id="genCardsBtn">批量生成</button>
          <button class="btn" id="copyCardsBtn" type="button">复制未使用卡密</button>
          <button class="btn" id="exportCardsBtn" type="button">导出未使用卡密</button>
        </div>
        <div id="cardsTable"></div>
        <div class="pager" id="cardsPager"></div>
      </div>
    </div>

    <!-- 历史版本 -->
    <div class="page hide" data-name="versions">
      <div class="page-head">
        <div>
          <div class="page-title" style="margin-bottom:4px">历史版本</div>
          <div class="page-sub">记录每次更新内容。新增时自动写入当前时间版本号，并同步为站点当前版本</div>
        </div>
        <button class="btn btn-primary" id="verAddBtn">新增版本</button>
      </div>
      <div class="card">
        <div id="versionsTable"></div>
      </div>
    </div>

    <!-- 设置 -->
    <div class="page hide" data-name="settings">
      <div class="page-title">站点设置</div>
      <div class="card">
        <div class="form-grid">
          <div>
            <label>站点名称</label>
            <input class="field" id="set_site_name">
          </div>
          <div>
            <label>站点描述</label>
            <input class="field" id="set_site_desc">
          </div>
          <div>
            <label>当前版本号（自动生成）</label>
            <input class="field" id="set_site_version" readonly placeholder="自动生成">
          </div>
          <div>
            <label>公告</label>
            <textarea class="field" id="set_announcement" rows="2"></textarea>
          </div>
          <div>
            <label>每次解析消耗点数</label>
            <input class="field" id="set_parse_cost" type="number" min="1">
          </div>
          <div>
            <label>注册赠送点数</label>
            <input class="field" id="set_register_points" type="number" min="0">
          </div>
          <div>
            <label>1 元兑换点数</label>
            <input class="field" id="set_points_per_yuan" type="number" min="1">
          </div>
        </div>
        <button class="btn btn-primary" id="saveSettingsBtn" style="margin-top:16px">保存基础设置</button>
      </div>

      <div class="page-title">公众号弹窗引导</div>
      <div class="card">
        <div class="page-sub" style="margin-bottom:14px">开启后前台会弹出公众号引导弹窗，用户关闭后 7 天内不再显示</div>
        <div class="form-grid">
          <div>
            <label>弹窗开关</label>
            <select class="field" id="set_wechat_enabled">
              <option value="0">关闭</option>
              <option value="1">开启</option>
            </select>
          </div>
          <div>
            <label>公众号名称</label>
            <input class="field" id="set_wechat_name" placeholder="如：某某科技">
          </div>
          <div>
            <label>二维码图片地址</label>
            <input class="field" id="set_wechat_qrcode" placeholder="https://...（图床图片 URL）">
          </div>
          <div>
            <label>引导文案</label>
            <input class="field" id="set_wechat_desc" placeholder="如：关注公众号，获取更多解析次数">
          </div>
        </div>
        <button class="btn btn-primary" id="saveWechatBtn" style="margin-top:16px">保存公众号设置</button>
      </div>

      <div class="page-title">微信 / QQ 分享卡片</div>
      <div class="card">
        <div class="page-sub" style="margin-bottom:14px">好友、群、朋友圈、QQ 空间识别链接时展示的标题、描述和封面图。留空则回退到站点名称与站点描述。封面图请使用可公网访问的完整图片 URL（建议 300x300 以上）。</div>
        <div class="form-grid">
          <div>
            <label>分享标题</label>
            <input class="field" id="set_share_title" placeholder="如：好东西分享给你">
          </div>
          <div>
            <label>分享描述</label>
            <input class="field" id="set_share_desc" placeholder="如：短视频一键去水印">
          </div>
          <div>
            <label>分享封面图</label>
            <input class="field" id="set_share_image" placeholder="https://...（图床图片 URL）">
          </div>
        </div>
        <button class="btn btn-primary" id="saveShareBtn" style="margin-top:16px">保存分享设置</button>
      </div>

      <div class="page-title">易支付配置</div>
      <div class="card">
        <div class="form-grid">
          <div>
            <label>支付通道开关</label>
            <select class="field" id="set_epay_enabled">
              <option value="0">关闭</option>
              <option value="1">开启</option>
            </select>
          </div>
          <div>
            <label>可用支付方式（逗号分隔）</label>
            <input class="field" id="set_epay_pay_types" placeholder="alipay,wxpay">
          </div>
          <div>
            <label>易支付 API 地址</label>
            <input class="field" id="set_epay_api" placeholder="https://www.ezfp.cn/">
          </div>
          <div>
            <label>商户 ID (pid)</label>
            <input class="field" id="set_epay_pid">
          </div>
          <div>
            <label>平台公钥（验签用）</label>
            <textarea class="field" id="set_epay_public_key" rows="4" placeholder="MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8..."></textarea>
          </div>
          <div>
            <label>商户私钥（签名用）</label>
            <textarea class="field" id="set_epay_private_key" rows="4" placeholder="MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBK..."></textarea>
          </div>
        </div>
        <button class="btn btn-primary" id="saveEpayBtn" style="margin-top:16px">保存支付配置</button>
        <button class="btn btn-ghost" id="epayCheckBtn" style="margin-top:16px;margin-left:8px">密钥自检</button>
        <div class="hide" id="epayCheckResult" style="margin-top:14px;font-size:13px"></div>
        <div class="page-title" style="margin-top:18px">支付回调配置</div>
        <p style="font-size:13px;color:#8a90a3">
          在易支付后台将「异步通知地址」配置为：<code><?= htmlspecialchars(($cfg = cfg('site_url')) ? $cfg : (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . '/pay_notify.php') ?></code><br>
          「同步跳转地址」配置为：<code><?= htmlspecialchars(($cfg = cfg('site_url')) ? $cfg : (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . '/pay_return.php') ?></code>
        </p>
      </div>

      <div class="page-title">支付宝当面付配置</div>
      <div class="card">
        <div class="form-grid">
          <div>
            <label>支付宝当面付开关</label>
            <select class="field" id="set_alipay_enabled">
              <option value="0">关闭</option>
              <option value="1">开启</option>
            </select>
          </div>
          <div>
            <label>支付宝 App ID</label>
            <input class="field" id="set_alipay_app_id" placeholder="2016xxxxxxxxxxxx">
          </div>
          <div>
            <label>应用私钥（RSA2，签名用）</label>
            <textarea class="field" id="set_alipay_private_key" rows="4" placeholder="MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBK..."></textarea>
          </div>
          <div>
            <label>支付宝公钥（验签用，非应用公钥）</label>
            <textarea class="field" id="set_alipay_public_key" rows="4" placeholder="在支付宝开放平台「开发设置-接口加签方式」中查看/下载的支付宝公钥"></textarea>
          </div>
        </div>
        <button class="btn btn-primary" id="saveAlipayBtn" style="margin-top:16px">保存支付宝配置</button>
          <div class="page-title" style="margin-top:18px">支付回调说明</div>
        <p style="font-size:13px;color:#8a90a3">
          系统会在下单时自动使用「异步通知地址」：<code><?= htmlspecialchars(($cfg = cfg('site_url')) ? $cfg : (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . '/alipay_notify.php') ?></code><br>
          请在支付宝开放平台开通「当面付」产品，确保应用已上线，并在应用设置中配置好「接口加签方式」（RSA2）。
        </p>
      </div>

      <div class="page-title">Bark 通知配置</div>
      <div class="card">
        <div class="page-sub" style="margin-bottom:14px">通过 Bark 向管理员推送「用户注册」「用户充值」通知。服务器地址留空使用官方 <code>https://api.day.app</code>。</div>
        <div class="form-grid">
          <div>
            <label>通知总开关</label>
            <select class="field" id="set_bark_enabled">
              <option value="0">关闭</option>
              <option value="1">开启</option>
            </select>
          </div>
          <div>
            <label>Bark 服务器地址</label>
            <input class="field" id="set_bark_server" placeholder="https://api.day.app">
          </div>
          <div>
            <label>推送 Key</label>
            <input class="field" id="set_bark_key" placeholder="Bark App 中的推送 Key">
          </div>
          <div>
            <label>铃声（可选）</label>
            <input class="field" id="set_bark_sound" placeholder="如：alarm、minute、payments，留空用默认铃声">
          </div>
          <div>
            <label>用户注册通知</label>
            <select class="field" id="set_bark_notify_register">
              <option value="0">关闭</option>
              <option value="1">开启</option>
            </select>
          </div>
          <div>
            <label>用户充值通知</label>
            <select class="field" id="set_bark_notify_recharge">
              <option value="0">关闭</option>
              <option value="1">开启</option>
            </select>
          </div>
        </div>
        <button class="btn btn-primary" id="saveBarkBtn" style="margin-top:16px">保存 Bark 配置</button>
      </div>
    </div>
  </main>
</div>

<!-- 用户操作弹窗 -->
<div class="modal-mask hide" id="userModal">
  <div class="modal">
    <div class="modal-title" id="userModalTitle">操作</div>
    <div id="userModalBody"></div>
  </div>
</div>

<!-- 解析类型编辑弹窗 -->
<div class="modal-mask hide" id="ptModal">
  <div class="modal">
    <div class="modal-title" id="ptModalTitle">新增解析类型</div>
    <input type="hidden" id="ptId">
    <div class="form-grid">
      <div>
        <label>类型名称</label>
        <input class="field" id="ptName" placeholder="如：抖音主页解析">
      </div>
      <div>
        <label>类型标识</label>
        <input class="field" id="ptKey" placeholder="如：dyzy（小写字母数字下划线中划线）">
      </div>
      <div>
        <label>是否启用</label>
        <div class="radio-row">
          <label class="radio-item"><input type="radio" name="ptEnabled" value="1" checked> 是</label>
          <label class="radio-item"><input type="radio" name="ptEnabled" value="0"> 否</label>
        </div>
      </div>
      <div>
        <label>排序（越小越靠前）</label>
        <input class="field" id="ptSort" type="number" min="0" placeholder="如：1">
      </div>
    </div>
    <button class="btn btn-primary btn-block" id="ptSaveBtn" style="margin-top:18px">保存</button>
  </div>
</div>

<!-- 版本编辑弹窗 -->
<div class="modal-mask hide" id="verModal">
  <div class="modal">
    <div class="modal-title" id="verModalTitle">新增版本</div>
    <input type="hidden" id="verId">
    <div class="form-grid">
      <div>
        <label>版本号（自动生成）</label>
        <input class="field" id="verVersion" readonly placeholder="保存时自动生成">
      </div>
      <div>
        <label>类型</label>
        <select class="field" id="verType">
          <option value="update">更新</option>
          <option value="optimize">优化</option>
          <option value="fix">修复</option>
        </select>
      </div>
      <div class="form-grid-full">
        <label>更新标题</label>
        <input class="field" id="verTitle" placeholder="如：新增微信 QQ 分享卡片">
      </div>
      <div class="form-grid-full">
        <label>更新内容</label>
        <textarea class="field" id="verContent" rows="3" placeholder="详细更新说明（可选）"></textarea>
      </div>
    </div>
    <button class="btn btn-primary btn-block" id="verSaveBtn" style="margin-top:18px">保存</button>
  </div>
</div>

<!-- 接口编辑弹窗 -->
<div class="modal-mask hide" id="apiModal">
  <div class="modal modal-lg">
    <div class="modal-title" id="apiModalTitle">新增接口</div>
    <input type="hidden" id="apiId">
    <div class="form-grid">
      <div>
        <label>接口名称</label>
        <input class="field" id="apiName" placeholder="如：抖音">
      </div>
      <div>
        <label>接口标识</label>
        <input class="field" id="apiSlug" placeholder="如：douyin（* 为通用接口）">
      </div>
      <div>
        <label>解析类型</label>
        <select class="field" id="apiParseType">
          <option value="">自动识别</option>
        </select>
      </div>
      <div class="form-grid-full">
        <label>接口图标</label>
        <input class="field" id="apiIcon" placeholder="远程图片请放链接，本地图片请自行上传后填路径">
      </div>
      <div>
        <label>是否启用</label>
        <div class="radio-row">
          <label class="radio-item"><input type="radio" name="apiEnabled" value="1" checked> 是</label>
          <label class="radio-item"><input type="radio" name="apiEnabled" value="0"> 否</label>
        </div>
      </div>
      <div>
        <label>排序（越小越靠前）</label>
        <input class="field" id="apiSort" type="number" min="0" placeholder="如：1">
      </div>
    </div>
    <div class="form-grid">
      <div class="form-grid-full">
        <label>接口地址</label>
        <input class="field" id="apiUrl" placeholder="远程请求接口地址，末尾以 url= 结尾">
      </div>
      <div>
        <label>返回数组</label>
        <input class="field" id="apiRespData" placeholder="接口返回数组的字段名（空=根级）">
      </div>
      <div>
        <label>返回标题</label>
        <input class="field" id="apiRespTitle" placeholder="如：title">
      </div>
      <div>
        <label>返回视频</label>
        <input class="field" id="apiRespVideo" placeholder="如：playAddr">
      </div>
      <div>
        <label>返回图片</label>
        <input class="field" id="apiRespImg" placeholder="如：cover">
      </div>
    </div>
    <button class="btn btn-primary btn-block" id="apiSaveBtn" style="margin-top:18px">保存接口</button>
  </div>
</div>

<div class="toast hide" id="toast"></div>

<script>
window.ADMIN = {
  siteUrl: <?= json_encode(cfg('site_url', '')) ?>,
  csrf: <?= json_encode(csrf_token()) ?>
};
</script>
<script src="../assets/js/admin.js?v=20260902h"></script>
</body>
</html>
