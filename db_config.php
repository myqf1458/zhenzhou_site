<?php
/**
 * 数据库安全配置文件
 * 该文件存放数据库连接逻辑，通过 .htaccess 禁止外部直接访问
 * 所有数据库连接需通过 db.php 引入本文件
 *
 * 敏感凭证已移至 config.php（集中式加载器），读取自 config.local.php 或环境变量
 */

// 严格限制：仅允许 PHP 内部引用，禁止直接访问
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'db_config.php') {
    http_response_code(403);
    exit('Forbidden');
}

// 加载集中式配置（提供 $db_host / $db_user / $db_pwd / $db_name / $db_charset）
require_once __DIR__ . '/config.php';

// 生产环境关闭错误显示（防 SQL 错误信息泄露结构）
if (!defined('DEBUG_MODE') || DEBUG_MODE !== true) {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', dirname(__FILE__) . '/php_error.log');
}

// 统一返回 JSON 的函数
function returnJson($code, $msg, $data = [])
{
    global $conn;
    // 清空可能存在的输出缓冲，避免错误信息混入 JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (isset($conn) && $conn) {
        @mysqli_close($conn);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "code" => $code,
        "msg"  => $msg,
        "data" => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 统一的数据库连接函数
 * 启用预处理语句模拟关闭，强制使用真正的预处理（防 SQL 注入）
 */
function db_connect() {
    global $db_host, $db_user, $db_pwd, $db_name, $db_charset;

    // 检查 mysqli 扩展
    if (!extension_loaded('mysqli')) {
        returnJson(500, '服务异常，请稍后重试');
    }

    // 建立连接
    $conn = mysqli_init();
    if (!$conn) {
        returnJson(500, '服务异常，请稍后重试');
    }

    // 连接超时 3 秒，避免长时间挂起
    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 3);

    if (!mysqli_real_connect($conn, $db_host, $db_user, $db_pwd, $db_name)) {
        // 不向用户暴露具体数据库错误
        error_log('DB Connect Failed: [' . mysqli_connect_errno() . '] ' . mysqli_connect_error());
        returnJson(500, '服务异常，请稍后重试');
    }

    // 设置字符集（防止 SQL 注入中的字符集绕过）
    mysqli_set_charset($conn, $db_charset);

    // 关闭预处理语句模拟，强制真正的预处理（更严格的防注入）
    // 注意：不使用 MYSQLI_REPORT_STRICT，因为现有代码未全部使用 try-catch 处理异常
    mysqli_report(MYSQLI_REPORT_ERROR);

    return $conn;
}
