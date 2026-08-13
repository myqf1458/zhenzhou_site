<?php
/**
 * 真昼小站 - 本地配置模板
 *
 * 使用方法：
 *   1. 复制本文件为 config.local.php：cp config.example.php config.local.php
 *   2. 在 config.local.php 中填写真实值
 *   3. config.local.php 已加入 .gitignore，不会被提交
 *
 * 也可通过环境变量覆盖（环境变量优先级更高），key 形如：
 *   DB_HOST / DB_USER / DB_PASS / DB_NAME
 *   SMTP_HOST / SMTP_PORT / SMTP_USER / SMTP_PASS
 *   SITE_URL / SITE_NAME
 *
 * 或直接运行后台安装向导：访问 admin/install.php ，将自动生成 config.local.php
 */

return [

    // ============== 数据库配置 ==============
    'db' => [
        'host'     => 'localhost',
        'user'     => 'your_db_user',
        'pass'     => 'your_db_password',
        'name'     => 'your_db_name',
        'charset'  => 'utf8mb4',
    ],

    // ============== SMTP 邮件配置 ==============
    // 用于发送验证码邮件，建议使用 QQ 邮箱的 SMTP 授权码（非登录密码）
    'smtp' => [
        'host'      => 'ssl://smtp.qq.com',
        'port'      => 465,
        'user'      => 'your_email@qq.com',
        'pass'      => 'your_smtp_auth_code',
        'from_name' => '真昼小站',
    ],

    // ============== 站点配置 ==============
    'site' => [
        'url'  => 'https://your-domain.com',
        'name' => '真昼小站',
    ],

    // ============== QQ 互联登录（可选） ==============
    // 也可在后台「QQ登录配置」页面填写，会写入 qq_oauth_config.php
    'qq' => [
        'app_id'       => '',
        'app_key'      => '',
        'redirect_uri' => '',
        'enabled'      => 0,
    ],
];
