-- =====================================================
-- 易支付平台 数据库初始化脚本 (MySQL 5.7+/8.0)
-- 字符集: utf8mb4  引擎: InnoDB
-- =====================================================

CREATE TABLE IF NOT EXISTS `pay_admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(32) NOT NULL,
  `password` VARCHAR(255) NOT NULL COMMENT '密码(哈希)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台管理员表';

CREATE TABLE IF NOT EXISTS `pay_merchants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL COMMENT '商户名称',
  `pid` VARCHAR(32) NOT NULL COMMENT '商户号',
  `secret` VARCHAR(64) NOT NULL COMMENT '商户密钥',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
  `fee_rate` DECIMAL(5,4) NOT NULL DEFAULT 0.0000 COMMENT '手续费率(0-1)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pid` (`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商户表';

CREATE TABLE IF NOT EXISTS `pay_channels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL COMMENT '通道名称',
  `code` VARCHAR(32) NOT NULL COMMENT '通道编码(alipay_f2f/wechat_native/wechat_h5/wechat_jsapi/upstream_epay)',
  `pay_type` VARCHAR(32) NOT NULL COMMENT '对商户开放的支付方式标识(alipay/wxpay等)',
  `enabled` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `config` TEXT NULL COMMENT '通道配置(JSON)',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付通道表';

CREATE TABLE IF NOT EXISTS `pay_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trade_no` VARCHAR(32) NOT NULL COMMENT '平台订单号',
  `out_trade_no` VARCHAR(64) NOT NULL COMMENT '商户订单号',
  `merchant_id` INT UNSIGNED NOT NULL,
  `channel_id` INT UNSIGNED NOT NULL,
  `pay_type` VARCHAR(32) NOT NULL COMMENT '支付方式',
  `name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '商品名称',
  `money` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待支付 1已支付 2已关闭',
  `notify_url` VARCHAR(512) NOT NULL DEFAULT '',
  `return_url` VARCHAR(512) NOT NULL DEFAULT '',
  `pay_trade_no` VARCHAR(64) NULL COMMENT '上游交易号',
  `paid_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trade_no` (`trade_no`),
  KEY `idx_merchant` (`merchant_id`),
  KEY `idx_out` (`out_trade_no`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付订单表';

CREATE TABLE IF NOT EXISTS `pay_settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台设置';

INSERT INTO `pay_settings` (`k`, `v`) VALUES
('site_name', '易支付平台'),
('settle_type', 't0'),
('alipay_f2f_enabled', '0'),
('wechat_enabled', '0')
ON DUPLICATE KEY UPDATE `k`=`k`;
