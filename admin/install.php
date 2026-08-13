<?php
@session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

$lockFile = __DIR__ . '/install.lock';
$installed = file_exists($lockFile);

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($installed) {
        echo json_encode(['code' => 403, 'msg' => '系统已安装，如需重新安装请删除 install.lock 文件']);
        exit;
    }

    $action = $_POST['action'];

    // 测试数据库连接
    if ($action === 'test_db') {
        $host = trim($_POST['db_host'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pwd = trim($_POST['db_pwd'] ?? '');
        $name = trim($_POST['db_name'] ?? '');
        $port = intval($_POST['db_port'] ?? 3306);

        if (empty($host) || empty($user) || empty($name)) {
            echo json_encode(['code' => 400, 'msg' => '请填写完整的数据库信息']);
            exit;
        }

        $conn = @mysqli_connect($host, $user, $pwd, $name, $port);
        if (!$conn) {
            echo json_encode(['code' => 500, 'msg' => '数据库连接失败: ' . mysqli_connect_error()]);
            exit;
        }

        mysqli_set_charset($conn, 'utf8mb4');
        $version = mysqli_get_server_info($conn);
        mysqli_close($conn);

        echo json_encode(['code' => 200, 'msg' => '数据库连接成功', 'data' => ['version' => $version]]);
        exit;
    }

    // 执行安装
    if ($action === 'install') {
        $host = trim($_POST['db_host'] ?? '');
        $port = intval($_POST['db_port'] ?? 3306);
        $user = trim($_POST['db_user'] ?? '');
        $pwd = trim($_POST['db_pwd'] ?? '');
        $name = trim($_POST['db_name'] ?? '');
        $adminUser = trim($_POST['admin_user'] ?? '');
        $adminPwd = trim($_POST['admin_pwd'] ?? '');

        if (empty($host) || empty($user) || empty($name) || empty($adminUser) || empty($adminPwd)) {
            echo json_encode(['code' => 400, 'msg' => '请填写所有必填项']);
            exit;
        }

        if (mb_strlen($adminUser) < 3) {
            echo json_encode(['code' => 400, 'msg' => '管理员用户名至少3个字符']);
            exit;
        }
        if (mb_strlen($adminPwd) < 6) {
            echo json_encode(['code' => 400, 'msg' => '管理员密码至少6个字符']);
            exit;
        }

        // 连接数据库
        $conn = @mysqli_connect($host, $user, $pwd, $name, $port);
        if (!$conn) {
            echo json_encode(['code' => 500, 'msg' => '数据库连接失败: ' . mysqli_connect_error()]);
            exit;
        }
        mysqli_set_charset($conn, 'utf8mb4');

        $errors = [];
        $sqlFile = __DIR__ . '/install_schema.sql';

        // 1. 创建管理员表
        $sql = "CREATE TABLE IF NOT EXISTS `admin_users` (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100) DEFAULT '',
            nickname VARCHAR(50) DEFAULT '',
            role VARCHAR(20) DEFAULT 'admin',
            status TINYINT(1) DEFAULT 1,
            create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            update_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql)) $errors[] = 'admin_users: ' . mysqli_error($conn);

        // 2. 创建用户表
        $sql = "CREATE TABLE IF NOT EXISTS `user_info` (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            nickname VARCHAR(50) DEFAULT '',
            email VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            avatar VARCHAR(255) DEFAULT '',
            gender VARCHAR(10) DEFAULT 'unknown',
            role VARCHAR(20) DEFAULT 'user',
            create_time DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql)) $errors[] = 'user_info: ' . mysqli_error($conn);

        // 3. 创建留言表
        $sql = "CREATE TABLE IF NOT EXISTS `comments` (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            nickname VARCHAR(50) NOT NULL DEFAULT '',
            avatar VARCHAR(255) NOT NULL DEFAULT '',
            user_id INT(11) NOT NULL DEFAULT 0,
            content TEXT NOT NULL,
            user_ip VARCHAR(50) NOT NULL DEFAULT '',
            create_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql)) $errors[] = 'comments: ' . mysqli_error($conn);

        // 4. 创建社区帖子表
        $sql = "CREATE TABLE IF NOT EXISTS `community_posts` (
            id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) UNSIGNED NOT NULL DEFAULT 0,
            author VARCHAR(50) NOT NULL DEFAULT '',
            avatar VARCHAR(255) NOT NULL DEFAULT '',
            content TEXT NOT NULL,
            emoji VARCHAR(10) DEFAULT NULL,
            qq VARCHAR(20) NOT NULL DEFAULT '',
            status TINYINT UNSIGNED NOT NULL DEFAULT 1,
            user_ip VARCHAR(50) NOT NULL DEFAULT '',
            create_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            update_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_create_time (create_time),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql)) $errors[] = 'community_posts: ' . mysqli_error($conn);

        // 5. 创建举报表
        $sql = "CREATE TABLE IF NOT EXISTS `reports` (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            comment_id INT(11) NOT NULL,
            user_id INT(11) NOT NULL DEFAULT 0,
            reporter_name VARCHAR(50) NOT NULL DEFAULT '',
            reason VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('pending', 'processed', 'rejected') DEFAULT 'pending',
            processed_by INT(11) DEFAULT NULL,
            processed_time DATETIME DEFAULT NULL,
            create_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_comment_id (comment_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql)) $errors[] = 'reports: ' . mysqli_error($conn);

        // 6. 创建邮箱验证码表
        $sql = "CREATE TABLE IF NOT EXISTS `email_codes` (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) NOT NULL,
            code VARCHAR(10) NOT NULL,
            purpose VARCHAR(20) NOT NULL DEFAULT 'register',
            used TINYINT(1) DEFAULT 0,
            expire_time DATETIME NOT NULL,
            create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_purpose (purpose)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if (!mysqli_query($conn, $sql)) $errors[] = 'email_codes: ' . mysqli_error($conn);

        // 7. 插入社区示例数据（如果表为空）
        $check = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM community_posts");
        if ($check && ($row = mysqli_fetch_assoc($check)) && $row['cnt'] == 0) {
            mysqli_query($conn, "INSERT INTO community_posts (user_id, author, avatar, content, status, create_time) VALUES
                (0, '小真昼', 'img/5.jpg', '欢迎来到真昼社区！这里是大家交流分享的地方，可以发帖讨论动漫、分享生活日常。请保持友善，共同维护温馨的社区氛围。', 1, '2026-07-29 10:00:00'),
                (0, '白河千岁', 'img/5.jpg', '最近重温了邻家天使，真昼和周的日常太治愈了！每次看都觉得心里暖暖的，强烈推荐给大家～', 1, '2026-07-28 15:30:00')");
        }

        // 8. 创建管理员账号
        $checkAdmin = mysqli_query($conn, "SELECT id FROM admin_users WHERE username = '" . mysqli_real_escape_string($conn, $adminUser) . "' LIMIT 1");
        if ($checkAdmin && mysqli_num_rows($checkAdmin) == 0) {
            $hashedPwd = password_hash($adminPwd, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "INSERT INTO admin_users (username, password, nickname, role) VALUES (?, ?, '超级管理员', 'super_admin')");
            mysqli_stmt_bind_param($stmt, 'ss', $adminUser, $hashedPwd);
            if (!mysqli_stmt_execute($stmt)) {
                $errors[] = '管理员创建失败: ' . mysqli_stmt_error($stmt);
            }
        }

        mysqli_close($conn);

        if (!empty($errors)) {
            echo json_encode(['code' => 500, 'msg' => '安装过程中出现错误: ' . implode('; ', $errors)]);
            exit;
        }

        // 9. 写入配置文件 config.local.php（敏感凭证，已加入 .gitignore）
        $configContent = "<?php\n";
        $configContent .= "/**\n";
        $configContent .= " * 本地配置文件 - 由安装向导生成于 " . date('Y-m-d H:i:s') . "\n";
        $configContent .= " * 包含敏感凭证，请勿提交到版本仓库（已加入 .gitignore）\n";
        $configContent .= " * 如需修改 SMTP/站点配置，请参考 config.example.php 补充对应字段\n";
        $configContent .= " */\n";
        $configContent .= "return [\n";
        $configContent .= "    'db' => [\n";
        $configContent .= "        'host'    => " . var_export($host, true) . ",\n";
        $configContent .= "        'user'    => " . var_export($user, true) . ",\n";
        $configContent .= "        'pass'    => " . var_export($pwd, true) . ",\n";
        $configContent .= "        'name'    => " . var_export($name, true) . ",\n";
        $configContent .= "        'port'    => " . intval($port) . ",\n";
        $configContent .= "        'charset' => 'utf8mb4',\n";
        $configContent .= "    ],\n";
        $configContent .= "    'smtp' => [\n";
        $configContent .= "        'host'      => 'ssl://smtp.qq.com',\n";
        $configContent .= "        'port'      => 465,\n";
        $configContent .= "        'user'      => '',  // 请填写发件邮箱\n";
        $configContent .= "        'pass'      => '',  // 请填写 SMTP 授权码\n";
        $configContent .= "        'from_name' => '真昼小站',\n";
        $configContent .= "    ],\n";
        $configContent .= "    'site' => [\n";
        $configContent .= "        'url'  => '',  // 请填写站点地址，如 https://your-domain.com\n";
        $configContent .= "        'name' => '真昼小站',\n";
        $configContent .= "    ],\n";
        $configContent .= "];\n";

        $configFilePath = dirname(__DIR__) . '/config.local.php';
        $writeResult = @file_put_contents($configFilePath, $configContent, LOCK_EX);
        if ($writeResult === false) {
            echo json_encode(['code' => 500, 'msg' => '配置文件写入失败，请检查目录权限。请手动复制 config.example.php 为 config.local.php 并填写数据库信息。']);
            exit;
        }

        // 10. 创建安装锁文件
        @file_put_contents($lockFile, json_encode([
            'install_time' => date('Y-m-d H:i:s'),
            'admin_user' => $adminUser,
            'version' => '1.0.0'
        ], JSON_UNESCAPED_UNICODE));

        echo json_encode(['code' => 200, 'msg' => '安装成功']);
        exit;
    }

    echo json_encode(['code' => 400, 'msg' => '未知操作']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装向导 - 真昼小站</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-dark: #0f172a;
            --bg-card: #1e293b;
            --bg-input: #334155;
            --border-color: #475569;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --primary: rgb(147, 130, 220);
            --primary-light: rgb(167, 150, 240);
            --success: #22c55e;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-dark);
            min-height: 100vh;
            color: var(--text-primary);
            overflow-x: hidden;
        }

        /* 背景动画 */
        .bg-animation {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            overflow: hidden; z-index: 0;
        }
        .bg-gradient {
            position: absolute; width: 600px; height: 600px;
            border-radius: 50%; opacity: 0.12; filter: blur(80px);
            animation: float 20s ease-in-out infinite;
        }
        .bg-gradient:nth-child(1) {
            background: radial-gradient(circle, rgba(147,130,220,0.8) 0%, transparent 70%);
            top: -150px; left: -150px;
        }
        .bg-gradient:nth-child(2) {
            background: radial-gradient(circle, rgba(232,197,208,0.6) 0%, transparent 70%);
            bottom: -100px; right: -100px; animation-delay: -7s;
        }
        .bg-gradient:nth-child(3) {
            background: radial-gradient(circle, rgba(96,165,250,0.4) 0%, transparent 70%);
            top: 40%; left: 50%; transform: translate(-50%,-50%); animation-delay: -14s;
        }
        @keyframes float {
            0%,100% { transform: translate(0,0) scale(1); }
            33% { transform: translate(40px,-30px) scale(1.1); }
            66% { transform: translate(-30px,40px) scale(0.9); }
        }
        .bg-grid {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-image: linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px);
            background-size: 40px 40px; z-index: 1;
        }

        /* 主容器 */
        .container {
            position: relative; z-index: 10;
            min-height: 100vh; display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            padding: 40px 20px;
        }

        .install-card {
            width: 100%; max-width: 560px;
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(71, 85, 105, 0.5);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
            animation: cardIn 0.5s ease;
        }
        @keyframes cardIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* 头部 */
        .card-header {
            text-align: center; padding: 40px 40px 24px;
            border-bottom: 1px solid rgba(71,85,105,0.3);
        }
        .header-icon {
            width: 72px; height: 72px; margin: 0 auto 20px;
            border-radius: 20px;
            background: linear-gradient(135deg, rgba(147,130,220,0.2), rgba(232,197,208,0.2));
            border: 2px solid rgba(147,130,220,0.3);
            display: flex; align-items: center; justify-content: center;
            font-size: 32px; color: var(--primary-light);
            animation: pulse 3s ease-in-out infinite;
        }
        @keyframes pulse {
            0%,100% { box-shadow: 0 0 30px rgba(147,130,220,0.2); }
            50% { box-shadow: 0 0 50px rgba(147,130,220,0.4); }
        }
        .header-title {
            font-size: 24px; font-weight: 700; margin-bottom: 6px;
            background: linear-gradient(135deg, #fff, rgba(255,255,255,0.7));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .header-subtitle {
            font-size: 14px; color: var(--text-secondary);
        }

        /* 步骤指示器 */
        .steps {
            display: flex; justify-content: center; gap: 0;
            padding: 24px 40px;
            border-bottom: 1px solid rgba(71,85,105,0.3);
        }
        .step-item {
            display: flex; align-items: center; gap: 8px;
            color: var(--text-muted); font-size: 13px; transition: all 0.3s ease;
        }
        .step-item.active { color: var(--primary-light); }
        .step-item.completed { color: var(--success); }
        .step-circle {
            width: 28px; height: 28px; border-radius: 50%;
            border: 2px solid currentColor;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 600; transition: all 0.3s ease;
        }
        .step-item.active .step-circle {
            background: var(--primary); border-color: var(--primary); color: #fff;
            box-shadow: 0 0 16px rgba(147,130,220,0.4);
        }
        .step-item.completed .step-circle {
            background: var(--success); border-color: var(--success); color: #fff;
        }
        .step-line {
            width: 40px; height: 2px; background: rgba(71,85,105,0.4);
            margin: 0 4px; border-radius: 1px; transition: background 0.3s ease;
        }
        .step-line.completed { background: var(--success); }

        /* 内容区 */
        .card-body { padding: 32px 40px; min-height: 280px; }

        /* 步骤面板 */
        .step-panel { display: none; animation: fadeIn 0.4s ease; }
        .step-panel.show { display: block; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        /* 隐私条款 */
        .terms-box {
            background: rgba(15,23,42,0.6);
            border: 1px solid rgba(71,85,105,0.4);
            border-radius: 12px;
            padding: 20px;
            max-height: 220px; overflow-y: auto;
            margin-bottom: 20px;
            font-size: 13px; line-height: 1.8; color: var(--text-secondary);
        }
        .terms-box::-webkit-scrollbar { width: 6px; }
        .terms-box::-webkit-scrollbar-track { background: transparent; }
        .terms-box::-webkit-scrollbar-thumb { background: rgba(71,85,105,0.5); border-radius: 3px; }
        .terms-box h4 { color: var(--text-primary); margin-bottom: 8px; font-size: 14px; }
        .terms-box p { margin-bottom: 10px; }

        .checkbox-item {
            display: flex; align-items: flex-start; gap: 10px;
            cursor: pointer; user-select: none;
        }
        .checkbox-item input {
            width: 18px; height: 18px; margin-top: 2px;
            accent-color: var(--primary); cursor: pointer; flex-shrink: 0;
        }
        .checkbox-item label { font-size: 14px; color: var(--text-secondary); cursor: pointer; line-height: 1.6; }
        .checkbox-item input:checked + label { color: var(--text-primary); }

        /* 表单 */
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block; font-size: 13px; font-weight: 500;
            color: var(--text-secondary); margin-bottom: 6px;
        }
        .form-group label .req { color: var(--danger); margin-left: 2px; }

        .input-wrap { position: relative; }
        .input-wrap input {
            width: 100%; height: 42px;
            padding: 0 14px 0 42px;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: 14px; color: var(--text-primary);
            outline: none; transition: all 0.2s ease;
            font-family: inherit;
        }
        .input-wrap input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(147,130,220,0.15);
        }
        .input-wrap input::placeholder { color: var(--text-muted); }
        .input-wrap .input-icon {
            position: absolute; left: 14px; top: 50%;
            transform: translateY(-50%);
            font-size: 15px; color: var(--text-muted); transition: color 0.2s ease;
        }
        .input-wrap input:focus + .input-icon { color: var(--primary); }

        .form-row { display: flex; gap: 12px; }
        .form-row .form-group { flex: 1; }

        /* 测试按钮 */
        .test-btn {
            padding: 0 16px; height: 42px;
            background: rgba(34,197,94,0.1);
            border: 1px solid rgba(34,197,94,0.3);
            border-radius: 10px;
            color: var(--success); font-size: 13px; font-weight: 500;
            cursor: pointer; transition: all 0.2s ease;
            display: flex; align-items: center; gap: 6px;
            white-space: nowrap; font-family: inherit;
        }
        .test-btn:hover { background: rgba(34,197,94,0.15); transform: translateY(-1px); }
        .test-btn:disabled { opacity: 0.6; cursor: not-allowed; }

        /* 密码强度 */
        .pwd-strength {
            height: 4px; border-radius: 2px; margin-top: 6px;
            background: rgba(71,85,105,0.4); overflow: hidden;
        }
        .pwd-strength-bar {
            height: 100%; width: 0; border-radius: 2px;
            transition: all 0.3s ease;
        }

        /* 安装结果 */
        .result-icon {
            width: 64px; height: 64px; margin: 0 auto 20px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 30px;
        }
        .result-icon.success { background: rgba(34,197,94,0.15); color: var(--success); }
        .result-icon.error { background: rgba(239,68,68,0.15); color: var(--danger); }
        .result-icon.loading {
            background: rgba(147,130,220,0.15); color: var(--primary-light);
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .result-title { text-align: center; font-size: 18px; font-weight: 600; margin-bottom: 8px; }
        .result-desc { text-align: center; font-size: 14px; color: var(--text-secondary); margin-bottom: 20px; }

        .install-log {
            background: rgba(15,23,42,0.6);
            border: 1px solid rgba(71,85,105,0.4);
            border-radius: 10px; padding: 12px 16px;
            max-height: 120px; overflow-y: auto;
            font-size: 12px; line-height: 1.8; color: var(--text-secondary);
            font-family: 'Courier New', monospace;
        }
        .install-log .log-ok { color: var(--success); }
        .install-log .log-err { color: var(--danger); }

        /* 底部按钮 */
        .card-footer {
            padding: 20px 40px;
            border-top: 1px solid rgba(71,85,105,0.3);
            display: flex; justify-content: space-between; align-items: center;
        }
        .btn {
            padding: 10px 28px; border: none; border-radius: 10px;
            font-size: 14px; font-weight: 600; cursor: pointer;
            transition: all 0.2s ease; display: flex; align-items: center; gap: 8px;
            font-family: inherit;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: #fff; box-shadow: 0 4px 16px rgba(147,130,220,0.3);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 24px rgba(147,130,220,0.4); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .btn-secondary {
            background: var(--bg-input); color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }
        .btn-secondary:hover { color: var(--text-primary); border-color: var(--text-muted); }

        /* 提示消息 */
        .alert {
            padding: 12px 16px; border-radius: 10px;
            margin-bottom: 16px; font-size: 13px;
            display: flex; align-items: center; gap: 8px;
        }
        .alert-error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: var(--danger); }
        .alert-success { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: var(--success); }
        .alert-info { background: rgba(147,130,220,0.08); border: 1px solid rgba(147,130,220,0.2); color: var(--primary-light); }

        /* 已安装遮罩 */
        .installed-notice {
            text-align: center; padding: 60px 40px;
        }
        .installed-notice .icon {
            width: 64px; height: 64px; margin: 0 auto 20px;
            border-radius: 50%; background: rgba(245,158,11,0.15);
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; color: var(--warning);
        }
        .installed-notice h2 { font-size: 20px; margin-bottom: 10px; }
        .installed-notice p { color: var(--text-secondary); font-size: 14px; margin-bottom: 24px; line-height: 1.8; }

        /* 响应式 */
        @media (max-width: 600px) {
            .container { padding: 16px; }
            .card-header, .card-body, .card-footer { padding-left: 24px; padding-right: 24px; }
            .steps { padding: 20px 16px; }
            .step-item span:last-child { display: none; }
            .form-row { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="bg-animation">
        <div class="bg-gradient"></div>
        <div class="bg-gradient"></div>
        <div class="bg-gradient"></div>
    </div>
    <div class="bg-grid"></div>

    <div class="container">
        <div class="install-card">
            <?php if ($installed): ?>
                <div class="installed-notice">
                    <div class="icon"><i class="fas fa-lock"></i></div>
                    <h2>系统已安装</h2>
                    <p>检测到安装锁文件存在，系统已经安装完成。<br>如需重新安装，请手动删除 <code style="background:var(--bg-input);padding:2px 8px;border-radius:4px;color:var(--primary-light);">admin/install.lock</code> 文件。</p>
                    <a href="login.php" class="btn btn-primary" style="display:inline-flex;">
                        <i class="fas fa-sign-in-alt"></i>前往登录
                    </a>
                </div>
            <?php else: ?>
                <!-- 头部 -->
                <div class="card-header">
                    <div class="header-icon"><i class="fas fa-rocket"></i></div>
                    <h1 class="header-title">真昼小站 安装向导</h1>
                    <p class="header-subtitle">按步骤完成系统安装配置</p>
                </div>

                <!-- 步骤指示器 -->
                <div class="steps">
                    <div class="step-item active" id="stepInd1">
                        <div class="step-circle">1</div>
                        <span>协议</span>
                    </div>
                    <div class="step-line" id="line1"></div>
                    <div class="step-item" id="stepInd2">
                        <div class="step-circle">2</div>
                        <span>数据库</span>
                    </div>
                    <div class="step-line" id="line2"></div>
                    <div class="step-item" id="stepInd3">
                        <div class="step-circle">3</div>
                        <span>管理员</span>
                    </div>
                    <div class="step-line" id="line3"></div>
                    <div class="step-item" id="stepInd4">
                        <div class="step-circle">4</div>
                        <span>完成</span>
                    </div>
                </div>

                <!-- 内容区 -->
                <div class="card-body">
                    <!-- Step 1: 隐私条款 -->
                    <div class="step-panel show" id="panel1">
                        <div class="terms-box">
                            <h4><i class="fas fa-shield-alt"></i> 隐私条款与使用协议</h4>
                            <p>欢迎使用真昼小站管理系统。在开始安装前，请仔细阅读以下条款：</p>
                            <p><strong>1. 数据收集</strong><br>本系统在运行过程中会收集用户注册信息（用户名、邮箱）、留言内容、社区动态及IP地址，仅用于网站运营与安全管理，不会向第三方泄露。</p>
                            <p><strong>2. 数据存储</strong><br>所有数据存储于您配置的MySQL数据库中，数据安全由服务器环境保障。建议定期进行数据库备份。</p>
                            <p><strong>3. 管理员责任</strong><br>安装者将获得超级管理员权限，需妥善保管账号密码，对站内发布内容负有管理责任。</p>
                            <p><strong>4. Cookie与Session</strong><br>系统使用Session和Cookie维持登录状态，不收集用于广告追踪的Cookie。</p>
                            <p><strong>5. 免责声明</strong><br>本系统为开源项目，按"原样"提供，不对因使用本系统产生的任何直接或间接损失承担责任。</p>
                            <p><strong>6. 知识产权</strong><br>用户发布的内容版权归原用户所有，站长有权对违规内容进行删除或屏蔽处理。</p>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="agreeTerms">
                            <label for="agreeTerms">我已阅读并同意以上隐私条款与使用协议</label>
                        </div>
                    </div>

                    <!-- Step 2: 数据库配置 -->
                    <div class="step-panel" id="panel2">
                        <div class="form-row">
                            <div class="form-group">
                                <label>数据库主机 <span class="req">*</span></label>
                                <div class="input-wrap">
                                    <input type="text" id="dbHost" placeholder="localhost" value="localhost">
                                    <i class="fas fa-server input-icon"></i>
                                </div>
                            </div>
                            <div class="form-group" style="max-width:100px;">
                                <label>端口</label>
                                <div class="input-wrap">
                                    <input type="text" id="dbPort" placeholder="3306" value="3306">
                                    <i class="fas fa-network-wired input-icon"></i>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>数据库用户名 <span class="req">*</span></label>
                            <div class="input-wrap">
                                <input type="text" id="dbUser" placeholder="root">
                                <i class="fas fa-user input-icon"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>数据库密码</label>
                            <div class="input-wrap">
                                <input type="password" id="dbPwd" placeholder="数据库密码">
                                <i class="fas fa-key input-icon"></i>
                            </div>
                        </div>
                        <div class="form-group" style="display:flex;gap:12px;align-items:flex-end;">
                            <div style="flex:1;">
                                <label>数据库名 <span class="req">*</span></label>
                                <div class="input-wrap">
                                    <input type="text" id="dbName" placeholder="shinamah_cn">
                                    <i class="fas fa-database input-icon"></i>
                                </div>
                            </div>
                            <button type="button" class="test-btn" id="testDbBtn">
                                <i class="fas fa-plug"></i>测试连接
                            </button>
                        </div>
                        <div id="dbAlert"></div>
                    </div>

                    <!-- Step 3: 管理员设置 -->
                    <div class="step-panel" id="panel3">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <span>此账号将拥有系统最高权限，请妥善保管</span>
                        </div>
                        <div class="form-group">
                            <label>管理员用户名 <span class="req">*</span></label>
                            <div class="input-wrap">
                                <input type="text" id="adminUser" placeholder="至少3个字符">
                                <i class="fas fa-user-shield input-icon"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>管理员密码 <span class="req">*</span></label>
                            <div class="input-wrap">
                                <input type="password" id="adminPwd" placeholder="至少6个字符">
                                <i class="fas fa-lock input-icon"></i>
                            </div>
                            <div class="pwd-strength"><div class="pwd-strength-bar" id="pwdBar"></div></div>
                        </div>
                        <div class="form-group">
                            <label>确认密码 <span class="req">*</span></label>
                            <div class="input-wrap">
                                <input type="password" id="adminPwd2" placeholder="再次输入密码">
                                <i class="fas fa-lock input-icon"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Step 4: 安装结果 -->
                    <div class="step-panel" id="panel4">
                        <div id="installResult"></div>
                    </div>
                </div>

                <!-- 底部按钮 -->
                <div class="card-footer">
                    <button class="btn btn-secondary" id="prevBtn" style="visibility:hidden;">
                        <i class="fas fa-arrow-left"></i> 上一步
                    </button>
                    <button class="btn btn-primary" id="nextBtn">
                        下一步 <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        let currentStep = 1;
        const totalSteps = 4;

        const stepIndicators = [
            null,
            document.getElementById('stepInd1'),
            document.getElementById('stepInd2'),
            document.getElementById('stepInd3'),
            document.getElementById('stepInd4')
        ];
        const stepLines = [null, null, document.getElementById('line1'), document.getElementById('line2'), document.getElementById('line3')];
        const panels = [
            null,
            document.getElementById('panel1'),
            document.getElementById('panel2'),
            document.getElementById('panel3'),
            document.getElementById('panel4')
        ];
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');

        function updateStep(step) {
            currentStep = step;
            panels.forEach((p, i) => { if (p) p.classList.toggle('show', i === step); });
            stepIndicators.forEach((el, i) => {
                if (!el) return;
                el.classList.remove('active', 'completed');
                if (i < step) el.classList.add('completed');
                else if (i === step) el.classList.add('active');
            });
            stepLines.forEach((el, i) => { if (el) el.classList.toggle('completed', i <= step); });

            prevBtn.style.visibility = step > 1 && step < 4 ? 'visible' : 'hidden';
            if (step === 4) {
                nextBtn.style.display = 'none';
            } else {
                nextBtn.style.display = 'flex';
                nextBtn.innerHTML = step === 3
                    ? '<i class="fas fa-cog"></i> 开始安装'
                    : '下一步 <i class="fas fa-arrow-right"></i>';
            }
        }

        function showAlert(containerId, type, msg) {
            const el = document.getElementById(containerId);
            el.innerHTML = `<div class="alert alert-${type}"><i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i><span>${msg}</span></div>`;
        }

        // 测试数据库连接
        document.getElementById('testDbBtn').addEventListener('click', function() {
            const host = document.getElementById('dbHost').value.trim();
            const port = document.getElementById('dbPort').value.trim() || '3306';
            const user = document.getElementById('dbUser').value.trim();
            const pwd = document.getElementById('dbPwd').value;
            const name = document.getElementById('dbName').value.trim();

            if (!host || !user || !name) {
                showAlert('dbAlert', 'error', '请填写完整的数据库信息');
                return;
            }

            const btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 连接中...';

            fetch('install.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'test_db',
                    db_host: host, db_port: port, db_user: user, db_pwd: pwd, db_name: name
                })
            })
            .then(r => r.json())
            .then(res => {
                if (res.code === 200) {
                    showAlert('dbAlert', 'success', '连接成功！MySQL版本: ' + res.data.version);
                } else {
                    showAlert('dbAlert', 'error', res.msg);
                }
            })
            .catch(() => showAlert('dbAlert', 'error', '请求失败，请检查网络'))
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-plug"></i>测试连接';
            });
        });

        // 密码强度
        document.getElementById('adminPwd').addEventListener('input', function() {
            const val = this.value;
            const bar = document.getElementById('pwdBar');
            let strength = 0;
            if (val.length >= 6) strength = 33;
            if (val.length >= 10 && /[A-Z]/.test(val)) strength = 66;
            if (val.length >= 12 && /[A-Z]/.test(val) && /[0-9]/.test(val) && /[^A-Za-z0-9]/.test(val)) strength = 100;
            bar.style.width = strength + '%';
            bar.style.background = strength <= 33 ? '#ef4444' : strength <= 66 ? '#f59e0b' : '#22c55e';
        });

        // 下一步
        nextBtn.addEventListener('click', function() {
            if (currentStep === 1) {
                if (!document.getElementById('agreeTerms').checked) {
                    alert('请先同意隐私条款与使用协议');
                    return;
                }
                updateStep(2);
            } else if (currentStep === 2) {
                const host = document.getElementById('dbHost').value.trim();
                const user = document.getElementById('dbUser').value.trim();
                const name = document.getElementById('dbName').value.trim();
                if (!host || !user || !name) {
                    showAlert('dbAlert', 'error', '请填写完整的数据库信息');
                    return;
                }
                updateStep(3);
            } else if (currentStep === 3) {
                const adminUser = document.getElementById('adminUser').value.trim();
                const adminPwd = document.getElementById('adminPwd').value;
                const adminPwd2 = document.getElementById('adminPwd2').value;

                if (adminUser.length < 3) { alert('管理员用户名至少3个字符'); return; }
                if (adminPwd.length < 6) { alert('管理员密码至少6个字符'); return; }
                if (adminPwd !== adminPwd2) { alert('两次输入的密码不一致'); return; }

                runInstall();
            }
        });

        // 上一步
        prevBtn.addEventListener('click', () => {
            if (currentStep > 1) updateStep(currentStep - 1);
        });

        // 执行安装
        function runInstall() {
            updateStep(4);
            const resultEl = document.getElementById('installResult');
            resultEl.innerHTML = `
                <div class="result-icon loading"><i class="fas fa-cog"></i></div>
                <div class="result-title">正在安装...</div>
                <div class="result-desc">请耐心等待，安装过程中请勿关闭页面</div>
                <div class="install-log" id="installLog">
                    <div>开始安装...</div>
                </div>
            `;

            const logEl = document.getElementById('installLog');
            const steps = ['创建数据表...', '创建管理员账号...', '写入配置文件...', '创建安装锁文件...'];
            let logIdx = 0;
            const logTimer = setInterval(() => {
                if (logIdx < steps.length) {
                    logEl.innerHTML += `<div>${steps[logIdx]}</div>`;
                    logEl.scrollTop = logEl.scrollHeight;
                    logIdx++;
                }
            }, 500);

            fetch('install.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'install',
                    db_host: document.getElementById('dbHost').value.trim(),
                    db_port: document.getElementById('dbPort').value.trim() || '3306',
                    db_user: document.getElementById('dbUser').value.trim(),
                    db_pwd: document.getElementById('dbPwd').value,
                    db_name: document.getElementById('dbName').value.trim(),
                    admin_user: document.getElementById('adminUser').value.trim(),
                    admin_pwd: document.getElementById('adminPwd').value
                })
            })
            .then(r => r.json())
            .then(res => {
                clearInterval(logTimer);
                if (res.code === 200) {
                    logEl.innerHTML += '<div class="log-ok">安装完成！</div>';
                    logEl.scrollTop = logEl.scrollHeight;
                    resultEl.innerHTML = `
                        <div class="result-icon success"><i class="fas fa-check"></i></div>
                        <div class="result-title">安装成功！</div>
                        <div class="result-desc">系统已成功安装，请使用刚设置的管理员账号登录</div>
                        <a href="login.php" class="btn btn-primary" style="display:flex;justify-content:center;margin:0 auto;width:fit-content;">
                            <i class="fas fa-sign-in-alt"></i>前往登录
                        </a>
                    `;
                } else {
                    logEl.innerHTML += `<div class="log-err">错误: ${res.msg}</div>`;
                    logEl.scrollTop = logEl.scrollHeight;
                    resultEl.innerHTML = `
                        <div class="result-icon error"><i class="fas fa-times"></i></div>
                        <div class="result-title">安装失败</div>
                        <div class="result-desc">${res.msg}</div>
                        <button class="btn btn-secondary" onclick="updateStep(3)" style="display:flex;justify-content:center;margin:0 auto;">
                            <i class="fas fa-arrow-left"></i> 返回修改
                        </button>
                    `;
                }
            })
            .catch(() => {
                clearInterval(logTimer);
                resultEl.innerHTML = `
                    <div class="result-icon error"><i class="fas fa-times"></i></div>
                    <div class="result-title">安装失败</div>
                    <div class="result-desc">网络请求失败，请检查服务器环境</div>
                    <button class="btn btn-secondary" onclick="updateStep(3)" style="display:flex;justify-content:center;margin:0 auto;">
                        <i class="fas fa-arrow-left"></i> 返回修改
                    </button>
                `;
            });
        }
    </script>
</body>
</html>
