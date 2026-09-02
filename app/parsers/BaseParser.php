<?php
/**
 * 解析器公共基类
 */

require_once __DIR__ . '/ParserInterface.php';

abstract class BaseParser implements ParserInterface
{
    /** 从分享文本提取 URL，无则抛异常 */
    protected function pickUrl($text)
    {
        $url = extract_url($text);
        if (!$url) {
            throw new RuntimeException('未能识别到有效链接，请粘贴完整的分享内容');
        }
        return $url;
    }

    /** 输出友好的平台接口错误 */
    protected function apiError($detail = '')
    {
        $msg = '解析失败，平台接口可能已更新或需要更换解析接口';
        if ($detail) {
            $msg .= '（' . $detail . '）';
        }
        throw new RuntimeException($msg);
    }

    protected function cleanUrl($u)
    {
        return preg_replace('#/[a-z_]+/watermark=true#i', '/', $u);
    }
}
