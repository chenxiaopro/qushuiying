<?php
/**
 * 本地部署配置模板
 * 复制本文件为 config.local.php 并修改数据库连接信息
 * 也可使用 pay/public/install.php 安装向导自动生成
 */
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => '你的数据库名',
        'user' => '你的数据库用户',
        'pass' => '你的数据库密码',
    ],
    'site_url' => '',
    'debug' => false,
];
