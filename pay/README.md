# 易支付平台（/pay）

独立部署的易支付平台，与去水印站点共用同一仓库、独立运行目录。去水印站点作为商户，通过彩虹易支付协议接入本平台完成充值支付。

## 功能

- 平台后台：商户管理、支付通道管理、订单查询、数据统计
- 下单接口（`submit.php`）、订单查询接口（`mapi.php`）、异步通知（`notify.php`）、收银台（`cashier.php`）
- 支付通道：
  - 支付宝当面付（扫码）
  - 微信 Native 扫码
  - 微信 H5 手机网页
  - 微信 JSAPI 公众号
  - 上游易支付转发（转单到第三方易支付）

## 部署

### 1. 环境要求

- PHP 7.4+ / 8.x（需启用 `pdo_mysql`、`curl`、`openssl`、`mbstring`）
- MySQL 5.7+ / MariaDB
- Nginx / Apache

### 2. 配置站点

以宝塔为例，新建站点，运行目录指向 `pay/public`，伪静态无需额外规则。

示例 Nginx 配置（可选，用于隐藏入口）：

```
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 3. 安装

浏览器访问 `http://你的域名/install.php`，按向导填写数据库信息并创建管理员账号。安装完成后自动生成 `pay/app/config.local.php`（该文件已被 `.gitignore` 排除，不提交）。

也可手动复制 `pay/app/config.local.example.php` 为 `pay/app/config.local.php` 修改数据库配置，然后导入 `pay/sql/install.sql`。

### 4. 初始化管理员（如未用向导）

```php
// 在命令行执行一次
require 'pay/app/init.php';
PDB::execute('INSERT INTO pay_admins(username,password) VALUES(?,?)', ['admin', password_hash('你的密码', PASSWORD_DEFAULT)]);
```

## 使用

### 平台后台

访问 `http://你的域名/admin/`，登录后：

1. 在「商户管理」新增商户，记录商户号（pid）与密钥（secret）
2. 在「通道管理」新增支付通道，填入对应配置

### 通道配置说明

**支付宝当面付**（`alipay_f2f`）：

```json
{
  "app_id": "你的应用AppID",
  "merchant_private_key": "应用私钥(一行，PEM)",
  "alipay_public_key": "支付宝公钥(PEM)"
}
```

**微信 Native / H5 / JSAPI**（`wechat_native` / `wechat_h5` / `wechat_jsapi`）：

```json
{
  "appid": "公众号或小程序AppID",
  "mchid": "微信商户号",
  "serial_no": "商户API证书序列号",
  "apiclient_key": "商户API私钥(apiclient_key.pem 内容)",
  "api_v3_key": "APIv3密钥"
}
```

**上游易支付转发**（`upstream_epay`）：

```json
{
  "api_url": "https://上游易支付域名",
  "pid": "上游商户号",
  "key": "上游商户密钥",
  "type": "alipay"
}
```

### 对接去水印站点

1. 在平台后台「商户管理」新增商户，得到 `pid` 与 `secret`
2. 去水印站点后台「支付设置」中填写：
   - 易支付 API 地址：`http://你的域名`（不含 `/submit.php`）
   - 商户 ID：`pid`
   - 商户 KEY：`secret`
3. 去水印站点会通过 `submit.php` 下单、`notify.php` 接收回调

## 安全说明

- 所有写操作校验 CSRF 与管理员登录态
- 下单/查询/通知均校验彩虹易支付 MD5 签名
- 异步通知使用数据库行锁保证幂等，避免重复加点
- 数据库密码存于 `pay/app/config.local.php`，已通过 `.gitignore` 排除提交
