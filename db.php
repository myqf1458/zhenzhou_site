<?php
/**
 * 数据库连接入口
 * 敏感配置已移至 db_config.php（被 .htaccess 禁止外部访问）
 */
require_once __DIR__ . '/db_config.php';

// 建立数据库连接
$conn = db_connect();
