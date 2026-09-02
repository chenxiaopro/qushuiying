-- =====================================================
-- 短视频去水印系统 数据库初始化脚本 (MySQL 5.7+/8.0)
-- 字符集: utf8mb4  引擎: InnoDB
-- =====================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(32) NOT NULL COMMENT '用户名',
  `password` VARCHAR(255) NOT NULL COMMENT '密码(哈希)',
  `points` INT NOT NULL DEFAULT 0 COMMENT '剩余点数',
  `total_points` INT NOT NULL DEFAULT 0 COMMENT '累计充值点数',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARCHAR(45) NULL,
  `last_parse_ts` INT NULL COMMENT '上次解析时间戳(频率限制)',
  `remember_token` VARCHAR(64) NULL COMMENT '30天免登录令牌(存哈希)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(32) NOT NULL,
  `password` VARCHAR(255) NOT NULL COMMENT '密码(哈希)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员表';

CREATE TABLE IF NOT EXISTS `parse_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `text` TEXT NOT NULL COMMENT '提交的分享文本',
  `platform` VARCHAR(32) NULL COMMENT '平台标识',
  `title` VARCHAR(512) NULL,
  `cover` TEXT NULL,
  `video_url` TEXT NULL COMMENT '无水印地址',
  `cost` INT NOT NULL DEFAULT 0 COMMENT '消耗点数',
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='解析记录';

CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_sn` VARCHAR(32) NOT NULL COMMENT '订单号',
  `user_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '支付金额(元)',
  `points` INT NOT NULL DEFAULT 0 COMMENT '到账点数',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待支付 1已支付 2已关闭',
  `pay_type` VARCHAR(16) NULL COMMENT 'alipay/wxpay',
  `trade_no` VARCHAR(64) NULL COMMENT '支付平台流水号',
  `remark` VARCHAR(255) NULL,
  `paid_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sn` (`order_sn`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充值订单表';

CREATE TABLE IF NOT EXISTS `cards` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_no` VARCHAR(32) NOT NULL COMMENT '卡密',
  `points` INT NOT NULL COMMENT '卡密点数',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0未使用 1已使用',
  `used_by` INT UNSIGNED NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_card` (`card_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充值卡密表';

CREATE TABLE IF NOT EXISTS `apis` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL COMMENT '接口名称',
  `slug` VARCHAR(32) NOT NULL COMMENT '接口标识(平台标识，*为通用)',
  `icon` VARCHAR(255) NULL COMMENT '接口图标',
  `enabled` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `api_url` VARCHAR(512) NULL COMMENT '远程接口地址',
  `resp_data` VARCHAR(64) NULL COMMENT '返回数组字段',
  `resp_title` VARCHAR(64) NULL COMMENT '返回标题字段',
  `resp_video` VARCHAR(64) NULL COMMENT '返回视频字段',
  `resp_img` VARCHAR(64) NULL COMMENT '返回图片字段',
  `parse_type` VARCHAR(20) NULL DEFAULT '' COMMENT '解析类型(dyzy主页/dsp短视频图集/douyinyh原画质/live实况，空=自动识别)',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序(越小越靠前)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_slug` (`slug`),
  UNIQUE KEY `uk_slug_name` (`slug`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='解析接口表';

CREATE TABLE IF NOT EXISTS `parse_types` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(32) NOT NULL COMMENT '显示名称',
  `key` VARCHAR(32) NOT NULL COMMENT '标识(前端mode与接口parse_type匹配)',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序(越小越靠前)',
  `enabled` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='解析类型表';

CREATE TABLE IF NOT EXISTS `versions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version` VARCHAR(20) NOT NULL COMMENT '版本号',
  `title` VARCHAR(100) NOT NULL COMMENT '更新标题',
  `type` VARCHAR(20) NOT NULL DEFAULT 'update' COMMENT '类型:update更新/optimize优化/fix修复',
  `content` TEXT NULL COMMENT '更新内容',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='版本更新记录';

CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点设置';

-- 默认设置
INSERT INTO `settings` (`k`, `v`) VALUES
('site_name', '短视频去水印'),
('site_desc', '抖音、快手等短视频一键去水印下载'),
('announcement', '欢迎使用，注册即送体验点数。'),
('parse_cost', '1'),
('register_points', '3'),
('points_per_yuan', '10'),
('epay_enabled', '0'),
('epay_api', ''),
('epay_pid', ''),
('epay_key', ''),
('epay_pay_types', 'alipay,wxpay'),
('wechat_enabled', '0'),
('wechat_name', ''),
('wechat_qrcode', ''),
('wechat_desc', ''),
('site_version', 'v1.1.0')
ON DUPLICATE KEY UPDATE `k`=`k`;

-- 默认解析类型
INSERT INTO `parse_types` (`name`, `key`, `sort`, `enabled`) VALUES
('抖音主页解析', 'dyzy', 1, 1),
('短视频/图集解析', 'dsp', 2, 1),
('抖音原画质', 'douyinyh', 3, 1),
('实况解析', 'live', 4, 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `sort`=VALUES(`sort`);

-- 解析接口请在后台「接口管理」中添加（此处不内置接口数据）
-- 接口地址示例（dsp 聚合接口，支持抖音、快手、小红书、B站、视频号、即梦、豆包、最右、微博、皮皮虾、千问、今日头条）：
--   https://syapi.chuangye.site/home/api?type=dsp&uid=XXX&key=XXX&url=
-- dsp 返回结构参考（成功 code="0001"）：
--   { "code":"0001", "title":"标题", "playAddr":"无水印视频直链", "cover":"封面",
--     "pics":[...], "music":{...}, "video_backup":[...], "platform":"抖音", "type":1 }
-- 字段映射建议：resp_title=title, resp_video=playAddr, resp_img=cover
-- 同一平台可配置多个接口（slug 相同），解析时默认取排序值最小的第一个
