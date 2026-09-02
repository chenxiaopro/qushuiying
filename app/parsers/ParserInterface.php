<?php
/**
 * 解析器接口
 * 新增平台适配：实现本接口并放到 app/parsers/ 下，然后在 ParserFactory 注册即可
 */
interface ParserInterface
{
    /** 平台标识，如 douyin */
    public static function key();

    /** 判断该分享文本是否属于本平台 */
    public static function supports($text);

    /**
     * 解析分享文本，返回
     * [
     *   'platform'   => 'douyin',
     *   'title'      => '视频标题',
     *   'cover'      => '封面图地址',
     *   'video_url'  => '无水印视频直链',
     *   'music'      => '背景音乐直链(可选)',
     * ]
     * 失败时抛异常
     */
    public function parse($text);
}
