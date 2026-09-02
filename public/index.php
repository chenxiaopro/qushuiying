<?php
/**
 * 前台单页面
 * 用户在同一个页面上完成：登录/注册 -> 粘贴分享内容 -> 解析去水印 -> 下载
 */
require_once __DIR__ . '/../app/init.php';
require_once __DIR__ . '/../app/payment/Epay.php';
require_once __DIR__ . '/../app/payment/AlipayWap.php';

$siteName = setting('site_name', '短视频去水印');
$siteDesc = setting('site_desc', '抖音、快手等短视频一键去水印下载');
$announcement = setting('announcement', '');
$wechatEnabled = (int)setting('wechat_enabled', 0) === 1;
$wechatName = trim(setting('wechat_name', ''));
$wechatQrcode = trim(setting('wechat_qrcode', ''));
$wechatDesc = trim(setting('wechat_desc', ''));
$parseCost = (int)setting('parse_cost', 1);
$registerPoints = (int)setting('register_points', 0);
$siteVersion = trim(setting('site_version', 'v1.1.0'));
$payTypes = Epay::payTypes();
$payEnabled = Epay::enabled();
$alipayEnabled = AlipayWap::enabled();
$onlinePayEnabled = $payEnabled || $alipayEnabled;
$payMethods = [];
if ($payEnabled) {
    foreach ($payTypes as $key => $name) {
        $payMethods[$key] = $name;
    }
}
if ($alipayEnabled) {
    $payMethods['alipay_wap'] = '支付宝支付';
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($siteName) ?> - 一键去水印</title>
<meta name="description" content="<?= htmlspecialchars($siteDesc) ?>">
<meta name="theme-color" content="#2f6bff">
<link rel="stylesheet" href="assets/css/style.css?v=20260902">
</head>
<body>

<header class="nav">
  <div class="container nav-inner">
    <div class="logo"><?= htmlspecialchars($siteName) ?></div>
    <div class="nav-right">
      <span class="balance hide" id="balance">余额：0 点</span>
      <button class="btn btn-primary btn-sm hide" id="btnRecharge" type="button">充值</button>
      <span class="username hide" id="username"></span>
      <button class="btn btn-ghost btn-sm hide" id="btnLogout" type="button">退出</button>
      <button class="btn btn-ghost btn-sm" id="btnLogin" type="button">登录</button>
      <button class="btn btn-primary btn-sm" id="btnRegister" type="button">注册</button>
    </div>
  </div>
</header>

<section class="hero">
  <div class="container">
    <h1 class="hero-title"><?= htmlspecialchars($siteName) ?></h1>
    <p class="hero-desc"><?= htmlspecialchars($siteDesc) ?></p>

    <div class="parse-box">
      <div class="parse-box-title">粘贴分享内容，一键去水印</div>
      <div class="parse-input-wrap">
        <textarea id="txt" class="parse-input" rows="4" placeholder="粘贴抖音 / 快手 / 小红书等 App 内复制的分享内容或链接"></textarea>
        <div class="parse-input-bar">
          <button class="btn btn-ghost btn-sm" id="btnPaste" type="button">粘贴</button>
          <button class="btn btn-ghost btn-sm" id="btnClear" type="button">清空</button>
        </div>
      </div>
      <div class="parse-type-wrap">
        <label class="parse-type-label" for="parseType">解析类型</label>
        <select id="parseType" class="parse-type">
          <option value="">自动识别</option>
        </select>
      </div>
      <button class="btn btn-primary btn-parse" id="btnParse" type="button">立即解析</button>
      <p class="parse-tip">登录后即可使用，每次解析消耗 <b><?= (int)$parseCost ?></b> 点 · 支持 Ctrl / Cmd + Enter</p>
    </div>

    <div class="feature-grid">
      <div class="feature-item">
        <div class="feature-icon">1</div>
        <div class="feature-text">
          <b>多平台识别</b>
          <span>抖音、快手、小红书、B 站等链接自动匹配</span>
        </div>
      </div>
      <div class="feature-item">
        <div class="feature-icon">2</div>
        <div class="feature-text">
          <b>视频 / 图集 / 实况</b>
          <span>无水印视频、图集打包、背景音乐一键下载</span>
        </div>
      </div>
      <div class="feature-item">
        <div class="feature-icon">3</div>
        <div class="feature-text">
          <b>点数付费</b>
          <span>卡密充值<?= $payEnabled ? ' + 在线支付' : '' ?>，解析成功后再扣点</span>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="container" id="resultSection" hidden>
  <div class="result-card" id="resultCard">
    <div class="result-info">
      <div class="result-head">
        <span class="badge" id="platformBadge">抖音</span>
        <h2 class="result-title" id="resultTitle"></h2>
      </div>
      <div class="result-author" id="resultAuthor"></div>
      <div class="result-desc" id="resultDesc"></div>
      <div class="result-music hide" id="resultMusic"></div>
    </div>
    <div class="result-media">
      <video id="videoPreview" class="video-preview" controls preload="none" poster=""></video>
      <div class="live-wrap hide" id="liveWrap">
        <div class="live-tag">实况动图</div>
        <video id="livePreview" class="video-preview" controls preload="none" playsinline poster=""></video>
      </div>
      <div class="images-grid" id="imagesWrap"></div>
    </div>
    <div class="result-actions">
      <a class="btn btn-primary" id="btnDownload" href="#">下载无水印视频</a>
      <a class="btn btn-ghost hide" id="btnDownloadBackup" href="#">备用下载</a>
      <a class="btn btn-ghost hide" id="btnDownloadMusic" href="#">下载背景音乐</a>
      <a class="btn btn-ghost hide" id="btnDownloadLive" href="#">下载实况视频</a>
      <button class="btn btn-primary hide" id="btnDownloadAll" type="button">打包下载图集</button>
    </div>
  </div>

  <div class="result-list hide" id="resultListWrap">
    <div class="result-list-head">
      <span class="badge" id="listPlatformBadge">抖音</span>
      <span class="result-list-count" id="listCount"></span>
    </div>
    <div class="result-list-grid" id="resultListGrid"></div>
  </div>
</section>

<footer class="footer">
  <div class="container"><?= htmlspecialchars($siteName) ?> · 本站仅提供技术交流演示，视频版权归原作者所有<?php if ($siteVersion !== ''): ?> · <?= htmlspecialchars($siteVersion) ?><?php endif; ?></div>
</footer>

<?php if ($announcement): ?>
<!-- 网站公告弹窗 -->
<div class="modal-mask hide" id="announcementModal">
  <div class="modal">
    <div class="modal-title">网站公告</div>
    <div class="announcement-modal-body"><?= nl2br(htmlspecialchars($announcement)) ?></div>
    <button class="btn btn-primary btn-block" id="announcementCloseBtn" style="margin-top:16px">我知道了</button>
  </div>
</div>
<?php endif; ?>

<?php if ($wechatEnabled && ($wechatName || $wechatQrcode)): ?>
<!-- 公众号引导弹窗 -->
<div class="modal-mask hide" id="wechatModal">
  <div class="modal wechat-modal">
    <div class="modal-title">关注公众号</div>
    <?php if ($wechatQrcode): ?>
    <div class="wechat-qrcode"><img src="<?= htmlspecialchars($wechatQrcode) ?>" alt="公众号二维码"></div>
    <?php endif; ?>
    <?php if ($wechatName): ?>
    <div class="wechat-name">公众号：<?= htmlspecialchars($wechatName) ?></div>
    <?php endif; ?>
    <?php if ($wechatDesc): ?>
    <div class="wechat-desc"><?= nl2br(htmlspecialchars($wechatDesc)) ?></div>
    <?php endif; ?>
    <button class="btn btn-primary btn-block" id="wechatCloseBtn" style="margin-top:16px">我知道了</button>
  </div>
</div>
<?php endif; ?>

<!-- 登录弹窗 -->
<div class="modal-mask hide" id="loginModal">
  <div class="modal">
    <div class="modal-title">登录</div>
    <label class="field-label">用户名</label>
    <input class="field" id="loginUser" maxlength="20">
    <label class="field-label">密码</label>
    <input class="field" id="loginPass" type="password" maxlength="32">
    <label class="remember-row">
      <input type="checkbox" id="loginRemember" value="1" checked> 30 天内免登录
    </label>
    <button class="btn btn-primary btn-block" id="loginSubmit">登录</button>
    <div class="modal-switch">还没有账号？<a href="javascript:;" data-switch="register">去注册</a></div>
  </div>
</div>

<!-- 注册弹窗 -->
<div class="modal-mask hide" id="registerModal">
  <div class="modal">
    <div class="modal-title">注册</div>
    <?php if ($registerPoints > 0): ?>
    <div class="register-bonus">注册即送 <b><?= $registerPoints ?></b> 点体验点数</div>
    <?php endif; ?>
    <label class="field-label">用户名</label>
    <input class="field" id="regUser" maxlength="20">
    <label class="field-label">密码</label>
    <input class="field" id="regPass" type="password" maxlength="32">
    <button class="btn btn-primary btn-block" id="regSubmit">注册</button>
    <div class="modal-switch">已有账号？<a href="javascript:;" data-switch="login">去登录</a></div>
  </div>
</div>

<!-- 充值弹窗 -->
<div class="modal-mask hide" id="rechargeModal">
  <div class="modal modal-lg">
    <div class="modal-title">充值中心</div>

    <div class="recharge-tabs">
      <?php if ($onlinePayEnabled): ?><span class="tab active" data-tab="pay">在线支付</span><?php endif; ?>
      <span class="tab<?= $onlinePayEnabled ? '' : ' active' ?>" data-tab="card">卡密充值</span>
    </div>

    <?php if ($onlinePayEnabled): ?>
    <div class="tab-panel" id="panel-pay">
      <div class="points-grid" id="pointsGrid"></div>
      <div class="pay-methods">
        <?php $firstPayKey = array_key_first($payMethods); ?>
        <?php foreach ($payMethods as $key => $name): ?>
        <label class="pay-method">
          <input type="radio" name="payType" value="<?= $key ?>" <?= $key === $firstPayKey ? 'checked' : '' ?>>
          <span><?= $name ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-primary btn-block" id="paySubmit">立即支付</button>
      <div class="pay-tip">支付成功后点数自动到账</div>
    </div>
    <?php endif; ?>

    <div class="tab-panel<?= $onlinePayEnabled ? ' hide' : '' ?>" id="panel-card">
      <label class="field-label">卡密</label>
      <input class="field" id="cardInput" placeholder="请输入充值卡密" maxlength="32">
      <button class="btn btn-primary btn-block" id="cardSubmit">卡密充值</button>
      <div class="pay-tip">请联系客服 / 站长购买充值卡密</div>
    </div>
  </div>
</div>

<!-- 支付跳转提示 -->
<div class="modal-mask hide" id="payLoadingModal">
  <div class="modal modal-sm">
    <div class="modal-title">正在跳转支付...</div>
    <p class="pay-tip">请在新的页面完成支付，支付成功后请返回本站</p>
  </div>
</div>

<div class="toast hide" id="toast"></div>

<script>
window.WM = {
  siteName: <?= json_encode($siteName, JSON_UNESCAPED_UNICODE) ?>,
  parseCost: <?= (int)$parseCost ?>,
  payEnabled: <?= $payEnabled ? 'true' : 'false' ?>,
  alipayEnabled: <?= $alipayEnabled ? 'true' : 'false' ?>,
  payTypes: <?= json_encode($payTypes, JSON_UNESCAPED_UNICODE) ?>,
  pointsPerYuan: <?= (int)setting('points_per_yuan', 10) ?>,
  announcement: <?= json_encode($announcement, JSON_UNESCAPED_UNICODE) ?>,
  csrf: <?= json_encode($csrf) ?>
};
</script>
<script src="assets/js/app.js?v=20260902b"></script>
</body>
</html>
