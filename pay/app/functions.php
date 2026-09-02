<?php
/**
 * 易支付平台通用函数
 */

function p_json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function p_ok($data = [])
{
    p_json_out(['code' => 0, 'msg' => 'ok', 'data' => $data]);
}

function p_fail($msg, $code = 1, $http = 200)
{
    p_json_out(['code' => $code, 'msg' => $msg], $http);
}

function p_fail_safe($e, $fallback = '服务暂时不可用，请稍后重试')
{
    if (pcfg('debug')) {
        p_fail('服务器异常：' . $e->getMessage(), 500);
    }
    error_log('[pay] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    p_fail($fallback, 500);
}

function p_input($key, $default = null)
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return $v;
}

function p_client_ip()
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

function p_csrf_token()
{
    if (empty($_SESSION['pay_csrf'])) {
        $_SESSION['pay_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['pay_csrf'];
}

function p_csrf_check()
{
    $token = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sess = (string)($_SESSION['pay_csrf'] ?? '');
    if ($sess === '' || $token === '' || !hash_equals($sess, $token)) {
        p_fail('会话已过期，请刷新页面后重试', 403);
    }
}

function p_rate_limit($action, $max, $window)
{
    $ip = preg_replace('/[^0-9a-fA-F:.]/', '', p_client_ip());
    $file = sys_get_temp_dir() . '/pay_rl_' . hash('sha256', $action . '|' . $ip);
    $now = time();
    $hits = [];
    $fp = fopen($file, 'c+');
    if ($fp === false) {
        return;
    }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    if ($raw !== false && $raw !== '') {
        $hits = json_decode($raw, true) ?: [];
    }
    $hits = array_values(array_filter($hits, function ($t) use ($now, $window) {
        return (int)$t > ($now - $window);
    }));
    if (count($hits) >= $max) {
        flock($fp, LOCK_UN);
        fclose($fp);
        p_fail('操作过于频繁，请稍后再试', 429);
    }
    $hits[] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($hits));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function p_http_get($url, $headers = [], $timeout = 15)
{
    return p_http_request('GET', $url, null, $headers, $timeout);
}

function p_http_post($url, $data, $headers = [], $timeout = 20, $asJson = false)
{
    $body = $asJson ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : http_build_query($data);
    return p_http_request('POST', $url, $body, $headers, $timeout, $asJson);
}

function p_http_request($method, $url, $body = null, $headers = [], $timeout = 20, $asJson = false)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125.0.0.0 Safari/537.36',
    ]);
    $hs = array_merge(['Accept: */*'], $headers);
    if ($body !== null) {
        if ($method === 'POST') {
            $hs[] = $asJson
                ? 'Content-Type: application/json; charset=utf-8'
                : 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hs);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP请求失败: ' . $err);
    }
    curl_close($ch);
    return ['body' => $resp, 'status' => (int)$info['http_code']];
}

/** 站点访问地址 */
function p_site_url()
{
    $url = pcfg('site_url');
    if ($url) {
        return rtrim($url, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
}

/** 生成商户号 */
function p_gen_pid()
{
    return (string)random_int(10000000, 99999999);
}

/** 生成平台订单号 */
function p_gen_trade_no()
{
    return date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/** 读取站点设置（带进程内缓存） */
function p_settings_all()
{
    if (!array_key_exists('PAY_SETTINGS', $GLOBALS) || !is_array($GLOBALS['PAY_SETTINGS'])) {
        $GLOBALS['PAY_SETTINGS'] = [];
        try {
            foreach (PDB::all('SELECT k,v FROM pay_settings') as $r) {
                $GLOBALS['PAY_SETTINGS'][$r['k']] = $r['v'];
            }
        } catch (Exception $e) {
            $GLOBALS['PAY_SETTINGS'] = [];
        }
    }
    return $GLOBALS['PAY_SETTINGS'];
}

function p_setting($key, $default = '')
{
    $all = p_settings_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function p_set_setting($key, $value)
{
    PDB::execute('INSERT INTO pay_settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$key, (string)$value]);
    $GLOBALS['PAY_SETTINGS'][$key] = (string)$value;
}
