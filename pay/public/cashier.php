<?php
/**
 * 收银台：展示支付二维码或跳转
 * 支持 action=poll 轮询订单状态
 */

require_once __DIR__ . '/../app/init.php';
require_once __DIR__ . '/../app/OrderService.php';

$tradeNo = (string)($_GET['trade_no'] ?? '');
$action = (string)($_GET['action'] ?? '');

if ($action === 'poll') {
    try {
        $order = PDB::one('SELECT * FROM pay_orders WHERE trade_no=?', [$tradeNo]);
        if (!$order) {
            p_fail('订单不存在', 404);
        }
        p_ok(['status' => (int)$order['status'], 'return_url' => $order['return_url']]);
    } catch (Exception $e) {
        p_fail_safe($e);
    }
}

if ($tradeNo === '') {
    http_response_code(404);
    echo '参数错误';
    exit;
}

try {
    $order = PDB::one('SELECT * FROM pay_orders WHERE trade_no=?', [$tradeNo]);
    if (!$order) {
        http_response_code(404);
        echo '订单不存在';
        exit;
    }
    if ((int)$order['status'] === 1) {
        header('Location: ' . ($order['return_url'] ?: './'), true, 302);
        exit;
    }

    $channel = ChannelFactory::make(ChannelFactory::resolve($order['pay_type'], (int)$order['channel_id'])['code']);
    $chRow = PDB::one('SELECT * FROM pay_channels WHERE id=?', [(int)$order['channel_id']]);
    $config = $chRow['config'] ? json_decode($chRow['config'], true) : [];
    $config = is_array($config) ? $config : [];
    $config['notify_url'] = p_site_url() . '/notify.php?channel=' . urlencode($chRow['code']);
    $config['return_url'] = p_site_url() . '/cashier.php?trade_no=' . urlencode($tradeNo);

    $result = $channel->create($order, $config, ['client_ip' => p_client_ip()]);
    $payResult = $result;
    $qrcode = '';
    $redirect = '';
    $jsapiData = null;

    if ($payResult['type'] === 'qrcode') {
        $qrcode = $payResult['data'];
    } elseif ($payResult['type'] === 'redirect') {
        $redirect = $payResult['data'];
    } elseif ($payResult['type'] === 'jsapi') {
        $jsapiData = $payResult['data'];
    }
} catch (Exception $e) {
    $errMsg = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars(p_setting('site_name', '易支付平台'), ENT_QUOTES); ?> - 收银台</title>
<link rel="stylesheet" href="./assets/css/style.css">
</head>
<body>
<div class="cashier-wrap">
  <div class="cashier-card">
    <h1><?php echo htmlspecialchars(p_setting('site_name', '易支付平台'), ENT_QUOTES); ?></h1>
    <div class="order-info">
      <div class="amount">¥ <?php echo htmlspecialchars(number_format((float)$order['money'], 2), ENT_QUOTES); ?></div>
      <div class="name"><?php echo htmlspecialchars($order['name'] ?: '订单支付', ENT_QUOTES); ?></div>
      <div class="trade">订单号：<?php echo htmlspecialchars($order['trade_no'], ENT_QUOTES); ?></div>
    </div>

    <?php if (!empty($errMsg)): ?>
      <div class="pay-error"><?php echo htmlspecialchars($errMsg, ENT_QUOTES); ?></div>
    <?php elseif ($qrcode !== ''): ?>
      <div class="pay-qrcode">
        <img id="qrcode-img" src="<?php echo htmlspecialchars($qrcode, ENT_QUOTES); ?>" alt="支付二维码">
        <p>请使用对应 App 扫码支付</p>
      </div>
    <?php elseif ($redirect !== ''): ?>
      <div class="pay-redirect">
        <p>正在跳转到支付页面...</p>
        <a class="btn" href="<?php echo htmlspecialchars($redirect, ENT_QUOTES); ?>">立即支付</a>
      </div>
      <script>setTimeout(function(){ location.href = <?php echo json_encode($redirect); ?>; }, 1500);</script>
    <?php elseif ($jsapiData !== null): ?>
      <div class="pay-jsapi">请在微信内完成支付</div>
    <?php endif; ?>

    <div class="pay-status" id="pay-status">正在等待支付...</div>
  </div>
</div>
<script>
(function(){
  var tradeNo = <?php echo json_encode($tradeNo); ?>;
  var timer = setInterval(function(){
    fetch('./cashier.php?action=poll&trade_no=' + encodeURIComponent(tradeNo))
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j.code === 0 && j.data.status === 1) {
          clearInterval(timer);
          document.getElementById('pay-status').textContent = '支付成功，正在跳转...';
          var url = j.data.return_url || './';
          setTimeout(function(){ location.href = url; }, 1200);
        }
      })
      .catch(function(){});
  }, 2500);
})();
</script>
</body>
</html>
