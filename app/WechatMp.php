<?php
/**
 * 微信公众号：签名校验、自定义菜单、回复 XML、绑定与签到
 */

class WechatMp
{
    public static function enabled()
    {
        return (int)setting('wxmp_enabled', 0) === 1
            && trim((string)setting('wxmp_token', '')) !== ''
            && trim((string)setting('wxmp_appid', '')) !== ''
            && trim((string)setting('wxmp_secret', '')) !== '';
    }

    public static function checkinPoints()
    {
        return max(1, (int)setting('wxmp_checkin_points', 1));
    }

    public static function verifySignature($token, $signature, $timestamp, $nonce)
    {
        $arr = [(string)$token, (string)$timestamp, (string)$nonce];
        sort($arr, SORT_STRING);
        return hash_equals(sha1(implode('', $arr)), (string)$signature);
    }

    public static function replyText($to, $from, $content)
    {
        $clean = function ($s) {
            return str_replace(['<![CDATA[', ']]>'], '', (string)$s);
        };
        $to = $clean($to);
        $from = $clean($from);
        $content = $clean($content);
        $time = time();
        return "<xml><ToUserName><![CDATA[{$to}]]></ToUserName><FromUserName><![CDATA[{$from}]]></FromUserName><CreateTime>{$time}</CreateTime><MsgType><![CDATA[text]]></MsgType><Content><![CDATA[{$content}]]></Content></xml>";
    }

    public static function parseXml($raw)
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }
        if (strlen($raw) > 65536) {
            return null;
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        if ($xml === false) {
            return null;
        }
        $out = [];
        foreach ($xml as $k => $v) {
            $out[(string)$k] = trim((string)$v);
        }
        return $out;
    }

    public static function accessToken($force = false)
    {
        $cacheFile = sys_get_temp_dir() . '/wm_wxmp_token_' . hash('sha256', (string)setting('wxmp_appid', ''));
        if (!$force && is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['token']) && (int)($cached['exp'] ?? 0) > time() + 60) {
                return (string)$cached['token'];
            }
        }
        $appid = trim((string)setting('wxmp_appid', ''));
        $secret = trim((string)setting('wxmp_secret', ''));
        if ($appid === '' || $secret === '') {
            throw new RuntimeException('请先填写公众号 AppID 与 AppSecret');
        }
        $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid='
            . rawurlencode($appid) . '&secret=' . rawurlencode($secret);
        $resp = json_decode((string)http_get($url, [], 12), true);
        if (!is_array($resp) || empty($resp['access_token'])) {
            $err = is_array($resp) ? ((string)($resp['errmsg'] ?? '') . ' (' . (string)($resp['errcode'] ?? '') . ')') : '空响应';
            throw new RuntimeException('获取 access_token 失败：' . $err);
        }
        $ttl = max(60, (int)($resp['expires_in'] ?? 7200) - 200);
        file_put_contents($cacheFile, json_encode([
            'token' => $resp['access_token'],
            'exp'   => time() + $ttl,
        ]));
        return (string)$resp['access_token'];
    }

    public static function createMenu()
    {
        $token = self::accessToken();
        $body = [
            'button' => [
                [
                    'name' => '每日签到',
                    'type' => 'click',
                    'key'  => 'DAILY_CHECKIN',
                ],
            ],
        ];
        $url = 'https://api.weixin.qq.com/cgi-bin/menu/create?access_token=' . rawurlencode($token);
        $resp = json_decode((string)http_post($url, $body, [], 12, true), true);
        if (!is_array($resp) || (int)($resp['errcode'] ?? -1) !== 0) {
            $err = is_array($resp) ? ((string)($resp['errmsg'] ?? '未知错误') . ' (' . (string)($resp['errcode'] ?? '') . ')') : '空响应';
            throw new RuntimeException('创建菜单失败：' . $err);
        }
        return true;
    }

    public static function handleMessage(array $msg)
    {
        $openid = (string)($msg['FromUserName'] ?? '');
        $mpId = (string)($msg['ToUserName'] ?? '');
        $type = (string)($msg['MsgType'] ?? '');
        if ($openid === '' || $mpId === '') {
            return 'success';
        }
        if ((int)setting('wxmp_enabled', 0) !== 1) {
            return self::replyText($openid, $mpId, '公众号签到暂未开放。');
        }

        if ($type === 'event') {
            $event = strtolower((string)($msg['Event'] ?? ''));
            $key = (string)($msg['EventKey'] ?? '');
            if ($event === 'click' && $key === 'DAILY_CHECKIN') {
                return self::replyText($openid, $mpId, self::doCheckin($openid));
            }
            if ($event === 'subscribe') {
                return self::replyText($openid, $mpId, "欢迎关注。请先在网站用户中心生成绑定码，再把绑定码发给我完成绑定。绑定后点底部「每日签到」即可领取点数。");
            }
            return 'success';
        }

        if ($type === 'text') {
            $content = trim((string)($msg['Content'] ?? ''));
            if ($content === '') {
                return 'success';
            }
            if (preg_match('/^[A-Za-z0-9]{6}$/', $content)) {
                return self::replyText($openid, $mpId, self::bindByCode($openid, strtoupper($content)));
            }
            $lower = mb_strtolower($content);
            if (in_array($lower, ['签到', 'qd', 'checkin', '每日签到'], true)) {
                return self::replyText($openid, $mpId, self::doCheckin($openid));
            }
            return self::replyText($openid, $mpId, "请发送 6 位绑定码完成绑定，或点击底部「每日签到」领取今日点数。");
        }

        return 'success';
    }

    public static function bindByCode($openid, $code)
    {
        $openid = trim((string)$openid);
        $code = strtoupper(trim((string)$code));
        if ($openid === '' || !preg_match('/^[A-Z0-9]{6}$/', $code)) {
            return '绑定码无效，请到网站用户中心重新生成。';
        }

        $bound = DB::one('SELECT id, username FROM users WHERE wx_openid=?', [$openid]);
        if ($bound) {
            return '当前微信已绑定账号「' . $bound['username'] . '」，无需重复绑定。';
        }

        $row = DB::one(
            'SELECT * FROM wx_bind_codes WHERE code=? AND used_at IS NULL AND expires_at>=NOW() ORDER BY id DESC LIMIT 1',
            [$code]
        );
        if (!$row) {
            return '绑定码无效或已过期，请到网站用户中心重新生成。';
        }

        $user = DB::one('SELECT id, username, wx_openid, status FROM users WHERE id=?', [(int)$row['user_id']]);
        if (!$user || (int)$user['status'] !== 1) {
            return '对应账号不存在或已被禁用。';
        }
        if (!empty($user['wx_openid'])) {
            return '该站内账号已绑定其他微信，请先在网站解绑。';
        }

        try {
            DB::pdo()->beginTransaction();
            $locked = DB::one('SELECT * FROM wx_bind_codes WHERE id=? FOR UPDATE', [(int)$row['id']]);
            if (!$locked || $locked['used_at'] !== null) {
                DB::pdo()->rollBack();
                return '绑定码已被使用，请重新生成。';
            }
            $taken = DB::one('SELECT id FROM users WHERE wx_openid=? FOR UPDATE', [$openid]);
            if ($taken) {
                DB::pdo()->rollBack();
                return '当前微信已绑定其他账号。';
            }
            $userLocked = DB::one('SELECT id, wx_openid FROM users WHERE id=? FOR UPDATE', [(int)$row['user_id']]);
            if (!$userLocked || $userLocked['wx_openid']) {
                DB::pdo()->rollBack();
                return '该站内账号已绑定其他微信。';
            }
            DB::execute('UPDATE users SET wx_openid=? WHERE id=?', [$openid, (int)$row['user_id']]);
            DB::execute('UPDATE wx_bind_codes SET used_at=NOW() WHERE id=?', [(int)$row['id']]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            if (DB::pdo()->inTransaction()) {
                DB::pdo()->rollBack();
            }
            error_log('[wm-wxmp] bind: ' . $e->getMessage());
            return '绑定失败，请稍后重试。';
        }

        return '绑定成功，账号「' . $user['username'] . '」。点击底部「每日签到」即可领取今日点数。';
    }

    public static function doCheckin($openid)
    {
        $openid = trim((string)$openid);
        if ($openid === '') {
            return '无法识别微信用户。';
        }
        $user = DB::one('SELECT id, username, status FROM users WHERE wx_openid=?', [$openid]);
        if (!$user) {
            return '尚未绑定站内账号。请先登录网站，在用户中心生成绑定码，再把绑定码发给我。';
        }
        if ((int)$user['status'] !== 1) {
            return '账号已被禁用，无法签到。';
        }

        $today = date('Y-m-d');
        $exists = DB::one('SELECT id, points FROM wx_checkins WHERE user_id=? AND checkin_date=?', [(int)$user['id'], $today]);
        if ($exists) {
            return '今天已经签到过了，获得 ' . (int)$exists['points'] . ' 点。明天再来。';
        }

        $points = self::checkinPoints();
        try {
            DB::pdo()->beginTransaction();
            $dup = DB::one('SELECT id, points FROM wx_checkins WHERE user_id=? AND checkin_date=? FOR UPDATE', [(int)$user['id'], $today]);
            if ($dup) {
                DB::pdo()->commit();
                return '今天已经签到过了，获得 ' . (int)$dup['points'] . ' 点。明天再来。';
            }
            DB::execute(
                'INSERT INTO wx_checkins(user_id,openid,points,checkin_date,created_at) VALUES(?,?,?,?,NOW())',
                [(int)$user['id'], $openid, $points, $today]
            );
            DB::execute('UPDATE users SET points=points+? WHERE id=?', [$points, (int)$user['id']]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            if (DB::pdo()->inTransaction()) {
                DB::pdo()->rollBack();
            }
            $again = DB::one('SELECT points FROM wx_checkins WHERE user_id=? AND checkin_date=?', [(int)$user['id'], $today]);
            if ($again) {
                return '今天已经签到过了，获得 ' . (int)$again['points'] . ' 点。明天再来。';
            }
            error_log('[wm-wxmp] checkin: ' . $e->getMessage());
            return '签到失败，请稍后重试。';
        }

        $fresh = DB::one('SELECT points FROM users WHERE id=?', [(int)$user['id']]);
        $bal = $fresh ? (int)$fresh['points'] : 0;
        return '签到成功，账号「' . $user['username'] . '」获得 ' . $points . ' 点，当前余额 ' . $bal . ' 点。';
    }

    public static function issueBindCode($userId)
    {
        $userId = (int)$userId;
        DB::execute('DELETE FROM wx_bind_codes WHERE user_id=? AND (used_at IS NOT NULL OR expires_at<NOW())', [$userId]);
        $alive = DB::one('SELECT code, expires_at FROM wx_bind_codes WHERE user_id=? AND used_at IS NULL AND expires_at>=NOW() ORDER BY id DESC LIMIT 1', [$userId]);
        if ($alive) {
            return $alive;
        }
        for ($i = 0; $i < 8; $i++) {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            try {
                DB::execute('INSERT INTO wx_bind_codes(user_id,code,expires_at,created_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE),NOW())', [$userId, $code]);
                return ['code' => $code, 'expires_at' => date('Y-m-d H:i:s', time() + 600)];
            } catch (Throwable $e) {
                // 唯一冲突则重试
            }
        }
        throw new RuntimeException('生成绑定码失败，请稍后重试');
    }
}
