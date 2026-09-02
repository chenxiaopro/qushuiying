<?php
/**
 * Bark 推送通知
 * 用于向管理员推送用户注册、用户充值等事件
 */

class NotifyBark
{
    /** 发送一条 Bark 推送，返回是否成功 */
    public static function send($title, $body)
    {
        if ((int)setting('bark_enabled', 0) !== 1) {
            return false;
        }
        $server = rtrim(trim((string)setting('bark_server', 'https://api.day.app')), '/');
        $key = trim((string)setting('bark_key', ''));
        if ($server === '' || $key === '') {
            return false;
        }
        try {
            $resp = http_post($server . '/push', [
                'device_key' => $key,
                'title'       => $title,
                'body'        => $body,
                'level'       => 'active',
            ], [], 10, true);
            return $resp !== false;
        } catch (Throwable $e) {
            error_log('[wm-bark] ' . $e->getMessage());
            return false;
        }
    }

    /** 用户注册通知 */
    public static function register($username)
    {
        if ((int)setting('bark_notify_register', 0) !== 1) {
            return false;
        }
        return self::send('新用户注册', '用户「' . $username . '」已注册');
    }

    /** 用户充值通知 */
    public static function recharge($username, $amount, $points)
    {
        if ((int)setting('bark_notify_recharge', 0) !== 1) {
            return false;
        }
        return self::send('用户充值', '用户「' . $username . '」充值 ' . $amount . ' 元，到账 ' . $points . ' 点');
    }
}
