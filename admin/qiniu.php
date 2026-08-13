<?php
// 七牛云对象存储管理
require_once 'config.php';
checkAdminLogin();

// 仅超级管理员可管理七牛配置（含敏感密钥）
if (!isSuperAdmin()) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>无权限</title><link rel="stylesheet" href="common.css"></head><body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;color:#8b8594;background:#fff;"><div style="text-align:center;"><div style="font-size:48px;margin-bottom:12px;">🔒</div><h2 style="color:#4a4556;">无访问权限</h2><p>该功能仅限超级管理员访问。</p><p style="margin-top:20px;"><a href="index.php" style="color:#b892c2;text-decoration:none;">返回仪表盘</a></p></div></body></html>';
    exit;
}

require_once 'qiniu_helper.php';

$admin = getCurrentAdmin();

// 自动创建配置表
$createTable = "
CREATE TABLE IF NOT EXISTS `qiniu_config` (
  `config_key` VARCHAR(50) NOT NULL,
  `config_value` TEXT,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
mysqli_query($conn, $createTable);

// 读取配置
function getQiniuConfig($conn, $key, $default = '') {
    $k = mysqli_real_escape_string($conn, $key);
    $r = mysqli_query($conn, "SELECT config_value FROM qiniu_config WHERE config_key = '{$k}'");
    if ($r && $row = mysqli_fetch_assoc($r)) {
        return $row['config_value'];
    }
    return $default;
}

// 写入配置
function upsertQiniuConfig($conn, $key, $value) {
    $k = mysqli_real_escape_string($conn, $key);
    $v = mysqli_real_escape_string($conn, (string)$value);
    mysqli_query($conn, "INSERT INTO qiniu_config (config_key, config_value) VALUES ('{$k}','{$v}') ON DUPLICATE KEY UPDATE config_value='{$v}'");
}

// 根据当前配置构造 Qiniu 实例
function makeQiniu($conn) {
    $ak     = trim((string)getQiniuConfig($conn, 'access_key'));
    $sk     = trim((string)getQiniuConfig($conn, 'secret_key'));
    $bucket = trim((string)getQiniuConfig($conn, 'bucket'));
    $domain = trim((string)getQiniuConfig($conn, 'domain'));
    $region = trim((string)getQiniuConfig($conn, 'region', 'z0'));
    if ($ak === '' || $sk === '' || $bucket === '') return null;
    return new Qiniu($ak, $sk, $bucket, $domain, $region);
}

// ===== AJAX 接口 =====
if (isset($_GET['action']) || isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    switch ($action) {
        // 保存配置
        case 'save_config': {
            $ak         = trim($_POST['access_key'] ?? '');
            $sk         = trim($_POST['secret_key'] ?? '');
            $bucket     = trim($_POST['bucket'] ?? '');
            $domain     = trim($_POST['domain'] ?? '');
            $region     = trim($_POST['region'] ?? 'z0');
            $is_enabled = isset($_POST['is_enabled']) ? '1' : '0';

            if ($bucket === '') {
                returnJson(400, 'Bucket 不能为空');
            }
            $validRegions = ['z0','cn-east-2','z1','z2','na0','as0'];
            if (!in_array($region, $validRegions, true)) {
                returnJson(400, '存储区域无效');
            }
            if ($domain !== '' && !preg_match('#^https?://#i', $domain)) {
                returnJson(400, '绑定域名需以 http:// 或 https:// 开头');
            }
            $domain = rtrim($domain, '/');

            upsertQiniuConfig($conn, 'access_key', $ak);
            upsertQiniuConfig($conn, 'bucket',     $bucket);
            upsertQiniuConfig($conn, 'domain',     $domain);
            upsertQiniuConfig($conn, 'region',     $region);
            upsertQiniuConfig($conn, 'is_enabled', $is_enabled);
            // SecretKey 仅在填写新值时更新（留空保留原值）
            if ($sk !== '') {
                upsertQiniuConfig($conn, 'secret_key', $sk);
            }
            returnJson(200, '配置已保存');
            break;
        }

        // 测试连接
        case 'test': {
            $q = makeQiniu($conn);
            if (!$q) returnJson(500, '请先完善 AccessKey / SecretKey / Bucket');
            $r = $q->testConnection();
            if ($r['ok']) returnJson(200, '连接成功，配置有效');
            returnJson(500, $r['msg']);
            break;
        }

        // 调试签名（仅本地调试用）
        case 'debug': {
            $q = makeQiniu($conn);
            if (!$q) returnJson(500, '请先完善 AccessKey / SecretKey / Bucket');
            $r = $q->listFiles(1, '', '', true);
            if ($r['ok']) returnJson(200, '连接成功', $r);
            returnJson(500, '签名调试信息', $r);
            break;
        }

        // 上传文件
        case 'upload': {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                returnJson(400, '请选择要上传的文件');
            }
            $q = makeQiniu($conn);
            if (!$q) returnJson(500, '请先完善七牛配置');

            $prefix = trim($_POST['prefix'] ?? '');
            $prefix = trim($prefix, '/');
            // 过滤前缀危险字符
            $prefix = str_replace(['..', '\\'], ['', '/'], $prefix);

            $safeName = basename($_FILES['file']['name']);
            $key = ($prefix !== '' ? $prefix . '/' : '') . date('YmdHis') . '_' . $safeName;
            // 限制 key 长度
            if (strlen($key) > 750) {
                $ext = pathinfo($safeName, PATHINFO_EXTENSION);
                $key = ($prefix !== '' ? $prefix . '/' : '') . date('YmdHis') . ($ext ? '.' . $ext : '');
            }
            $mime = $_FILES['file']['type'] ?: null;

            $r = $q->uploadFile($key, $_FILES['file']['tmp_name'], $mime);
            if (!$r['ok']) returnJson(500, $r['msg']);
            returnJson(200, '上传成功', $r['data']);
            break;
        }

        // 列举文件
        case 'list_files': {
            $q = makeQiniu($conn);
            if (!$q) returnJson(500, '请先完善七牛配置');
            $limit  = intval($_GET['limit'] ?? 50);
            if ($limit < 1 || $limit > 1000) $limit = 50;
            $prefix = trim($_GET['prefix'] ?? '');
            $marker = trim($_GET['marker'] ?? '');
            $r = $q->listFiles($limit, $prefix, $marker);
            if (!$r['ok']) returnJson(500, $r['msg']);
            returnJson(200, '获取成功', $r['data']);
            break;
        }

        // 删除文件
        case 'delete_file': {
            $q = makeQiniu($conn);
            if (!$q) returnJson(500, '请先完善七牛配置');
            $key = (string)($_POST['key'] ?? '');
            if ($key === '') returnJson(400, '文件 key 不能为空');
            $r = $q->deleteFile($key);
            if (!$r['ok']) returnJson(500, $r['msg']);
            returnJson(200, '删除成功');
            break;
        }

        default:
            returnJson(404, '未知操作');
    }
}

// 表单展示配置
$cfg = [
    'access_key' => getQiniuConfig($conn, 'access_key'),
    'secret_key' => getQiniuConfig($conn, 'secret_key'),
    'bucket'     => getQiniuConfig($conn, 'bucket'),
    'domain'     => getQiniuConfig($conn, 'domain'),
    'region'     => getQiniuConfig($conn, 'region', 'z0'),
    'is_enabled' => getQiniuConfig($conn, 'is_enabled', '0'),
];
$sk_set      = $cfg['secret_key'] !== '';
$configured  = ($cfg['access_key'] !== '' && $cfg['secret_key'] !== '' && $cfg['bucket'] !== '');
mysqli_close($conn);

// 区域选项
$regionOptions = [
    'z0'         => '华东-浙江（旧版通用域名 rs/rsf.qiniu.com）',
    'cn-east-2'  => '华东-浙江2（新版 区域域名 rs/rsf-cn-east-2.qiniuapi.com）',
    'z1'         => '华北-北京（旧版）',
    'cn-north-1' => '华北-北京（新版）',
    'z2'         => '华南-广州（旧版）',
    'cn-south-1' => '华南-广州（新版）',
    'na0'        => '北美-洛杉矶',
    'as0'        => '亚太-新加坡',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台管理系统 - 七牛存储</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="common.css">
    <style>
        :root {
            --primary: #c9a9d4;
            --primary-dark: #b892c2;
            --primary-light: #f3e8f7;
            --secondary: #e8c5d0;
            --success: #a8d5ba;
            --warning: #e8d4a8;
            --danger: #e8b0b0;
            --bg-page: #ffffff;
            --bg-card: rgba(255, 255, 255, 0.85);
            --bg-input: rgba(248, 244, 250, 0.8);
            --border-color: rgba(201, 169, 212, 0.3);
            --text-primary: #4a4556;
            --text-secondary: #8b8594;
            --text-muted: #b5b0bc;
            --sidebar-width: 240px;
            --header-height: 64px;
            --shadow-light: 0 4px 20px rgba(201, 169, 212, 0.1);
            --shadow-hover: 0 8px 30px rgba(201, 169, 212, 0.15);
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-page);
            background-image:
                radial-gradient(circle at 20% 80%, rgba(232, 197, 208, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(201, 169, 212, 0.15) 0%, transparent 50%);
            color: var(--text-primary);
            min-height: 100vh;
            margin: 0;
        }
        /* 侧边栏 */
        .sidebar {
            position: fixed; left: 0; top: 0; bottom: 0;
            width: var(--sidebar-width);
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border-right: 1px solid var(--border-color);
            z-index: 1000; display: flex; flex-direction: column;
        }
        .sidebar-header {
            height: var(--header-height);
            display: flex; align-items: center; padding: 0 24px;
            border-bottom: 1px solid var(--border-color);
        }
        .sidebar-logo { display: flex; align-items: center; gap: 12px; }
        .sidebar-logo .logo-icon {
            width: 36px; height: 36px; border-radius: 14px; overflow: hidden;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 12px rgba(201, 169, 212, 0.3);
            border: 2px solid rgba(201, 169, 212, 0.3);
        }
        .sidebar-logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; }
        .sidebar-logo .logo-text { font-size: 18px; font-weight: 600; color: var(--text-primary); }
        .sidebar-menu { flex: 1; padding: 16px 0; overflow-y: auto; }
        .menu-item a {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 24px; color: var(--text-secondary);
            text-decoration: none; font-size: 14px;
            transition: all 0.3s ease;
            border-left: 3px solid transparent; border-radius: 0 12px 12px 0;
        }
        .menu-item a:hover { background: rgba(201, 169, 212, 0.1); color: var(--text-primary); }
        .menu-item a.active { background: rgba(201, 169, 212, 0.15); color: var(--primary-dark); border-left-color: var(--primary); }
        .menu-item .icon { font-size: 17px; width: 20px; text-align: center; }
        .menu-item .badge {
            margin-left: auto; padding: 2px 8px;
            background: rgba(232, 176, 176, 0.2); color: var(--danger);
            font-size: 12px; border-radius: 10px;
        }
        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; }
        .header {
            height: var(--header-height);
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 24px; position: sticky; top: 0; z-index: 100;
        }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .header-title { font-size: 18px; font-weight: 600; margin: 0; }
        .header-breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); }
        .header-breadcrumb a { color: var(--text-secondary); text-decoration: none; }
        .header-breadcrumb a:hover { color: var(--primary-dark); }
        .header-right { display: flex; align-items: center; gap: 12px; }
        .header-action {
            width: 36px; height: 36px; background: var(--bg-input);
            border: 1px solid var(--border-color); border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; color: var(--text-secondary); cursor: pointer; transition: all 0.3s ease;
        }
        .header-action:hover { background: rgba(201, 169, 212, 0.15); color: var(--primary-dark); }
        .page-content { padding: 24px; }
        .footer { text-align: center; padding: 24px; color: var(--text-muted); font-size: 12px; }

        /* 卡片 */
        .card {
            background: var(--bg-card);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px; padding: 24px;
            margin-bottom: 24px; box-shadow: var(--shadow-light);
        }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .card-title { font-size: 16px; font-weight: 600; color: var(--text-primary); margin: 0; display: flex; align-items: center; gap: 10px; }
        .card-title i { color: var(--primary-dark); }
        .card-desc { font-size: 13px; color: var(--text-secondary); margin: 0 0 18px 0; }

        /* 表单 */
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-label { font-size: 13px; font-weight: 600; color: var(--text-primary); }
        .form-hint { font-size: 12px; color: var(--text-muted); }
        .form-input, .form-select {
            padding: 10px 14px; background: var(--bg-input);
            border: 1px solid var(--border-color); border-radius: 10px;
            font-size: 14px; color: var(--text-primary); outline: none;
            transition: all 0.2s; width: 100%; box-sizing: border-box;
        }
        .form-input:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(201, 169, 212, 0.15); }
        .checkbox-wrap { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--text-primary); }

        /* 按钮 */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px; border: none; border-radius: 10px;
            font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease;
            text-decoration: none;
        }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: #fff; }
        .btn-primary:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(201, 169, 212, 0.4); }
        .btn-ghost { background: var(--bg-input); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .btn-ghost:hover:not(:disabled) { color: var(--primary-dark); border-color: var(--primary); }
        .btn-danger { background: rgba(232, 176, 176, 0.2); color: #c0707a; border: 1px solid rgba(232, 176, 176, 0.5); }
        .btn-danger:hover:not(:disabled) { background: rgba(232, 176, 176, 0.35); }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        /* 状态标签 */
        .status-tag { padding: 3px 10px; font-size: 12px; border-radius: 20px; font-weight: 600; }
        .status-tag.ok { background: rgba(168, 213, 186, 0.25); color: #6fa886; }
        .status-tag.no { background: rgba(232, 176, 176, 0.25); color: #c0707a; }

        /* 上传区 */
        .upload-area {
            border: 2px dashed var(--border-color); border-radius: 14px;
            padding: 36px 20px; text-align: center; cursor: pointer;
            transition: all 0.3s ease; background: var(--bg-input);
        }
        .upload-area:hover, .upload-area.dragover { border-color: var(--primary); background: rgba(201, 169, 212, 0.12); }
        .upload-area i { font-size: 40px; color: var(--primary); margin-bottom: 12px; }
        .upload-area .t { font-size: 15px; color: var(--text-primary); font-weight: 600; }
        .upload-area .s { font-size: 12px; color: var(--text-muted); margin-top: 6px; }

        .upload-bar { margin-top: 16px; }
        .upload-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; background: var(--bg-input);
            border: 1px solid var(--border-color); border-radius: 10px; margin-bottom: 8px; font-size: 13px;
        }
        .upload-item .name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .upload-item .res.ok { color: #6fa886; }
        .upload-item .res.err { color: #c0707a; }

        /* 表格 */
        .data-card { overflow: hidden; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            background: rgba(201, 169, 212, 0.08); padding: 14px 16px;
            text-align: left; font-size: 13px; font-weight: 600;
            color: var(--text-secondary); border-bottom: 1px solid var(--border-color);
        }
        .data-table td { padding: 14px 16px; font-size: 13px; color: var(--text-primary); border-bottom: 1px solid rgba(201, 169, 212, 0.15); word-break: break-all; }
        .data-table tr:hover { background: rgba(201, 169, 212, 0.06); }
        .file-thumb { width: 44px; height: 44px; border-radius: 8px; object-fit: cover; background: var(--bg-input); }
        .file-icon { width: 44px; height: 44px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: var(--bg-input); color: var(--primary-dark); font-size: 18px; }
        .empty-row td { text-align: center; color: var(--text-muted); padding: 40px; }
        .action-btns { display: flex; gap: 6px; flex-wrap: wrap; }

        .toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .toolbar .form-input { width: auto; min-width: 200px; }
        .pagination { display: flex; gap: 8px; justify-content: center; margin-top: 16px; flex-wrap: wrap; }

        .message {
            padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 16px;
            display: none; align-items: center; gap: 8px;
        }
        .message.show { display: flex; }
        .message.success { background: rgba(168, 213, 186, 0.2); color: #5e9778; }
        .message.error { background: rgba(232, 176, 176, 0.25); color: #b06070; }

        .url-cell { display: flex; align-items: center; gap: 6px; max-width: 320px; }
        .url-cell a { color: var(--primary-dark); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .copy-btn { flex-shrink: 0; cursor: pointer; color: var(--text-secondary); }
        .copy-btn:hover { color: var(--primary-dark); }

        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .toolbar .form-input { min-width: 100%; }
            .url-cell { max-width: 100%; }
        }
    </style>
</head>
<body>
    <!-- 移动端菜单按钮 -->
    <div class="mobile-menu-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></div>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- 侧边栏 -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <div class="logo-icon">
                    <img src="logo.png" alt="Logo" onerror="this.style.display='none';this.parentElement.innerHTML='<div style=\'width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:16px;color:#c9a9d4;background:linear-gradient(135deg, #f3e8f7, #f9f0f3);\'><i class=\'fas fa-crown\'></i></div>';" />
                </div>
                <span class="logo-text">真昼小破站</span>
            </div>
        </div>
        <div class="sidebar-menu">
            <div class="menu-item"><a href="index.php"><i class="fas fa-chart-pie icon"></i><span>仪表盘</span></a></div>
            <div class="menu-item"><a href="users.php"><i class="fas fa-users icon"></i><span>用户管理</span></a></div>
            <div class="menu-item"><a href="community.php"><i class="fas fa-comments icon"></i><span>社区管理</span></a></div>
            <div class="menu-item"><a href="comments.php"><i class="fas fa-comment-dots icon"></i><span>留言管理</span></a></div>
            <div class="menu-item"><a href="bangumi.php"><i class="fas fa-tv icon"></i><span>追番管理</span></a></div>
            <div class="menu-item"><a href="reports.php"><i class="fas fa-flag icon"></i><span>举报处理</span></a></div>
            <div class="menu-item"><a href="admins.php"><i class="fas fa-shield-alt icon"></i><span>管理员管理</span></a></div>
            <div class="menu-item"><a href="security.php"><i class="fas fa-user-shield icon"></i><span>安全中心</span></a></div>
            <div class="menu-item"><a href="qiniu.php" class="active"><i class="fas fa-cloud icon"></i><span>七牛存储</span></a></div>
            <div class="menu-item"><a href="logout.php"><i class="fas fa-sign-out-alt icon"></i><span>退出登录</span></a></div>
        </div>
    </div>

    <!-- 主内容区 -->
    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1 class="header-title">七牛存储</h1>
                <div class="header-breadcrumb">
                    <a href="index.php">首页</a><span>/</span><span>七牛存储</span>
                </div>
            </div>
            <div class="header-right">
                <div class="header-action" title="刷新" onclick="loadFiles(true)"><i class="fas fa-sync-alt"></i></div>
            </div>
        </div>

        <div class="page-content">
            <!-- 全局提示 -->
            <div class="message" id="globalMsg"><i class="fas fa-info-circle"></i><span id="globalMsgText"></span></div>

            <!-- 配置卡片 -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-cog"></i> 存储配置</h3>
                    <span class="status-tag <?php echo $configured ? 'ok' : 'no'; ?>"><?php echo $configured ? '已配置' : '未配置'; ?></span>
                </div>
                <p class="card-desc">配置七牛云对象存储的访问密钥与空间信息。SecretKey 留空表示不修改；配置保存于数据库，仅超级管理员可访问。</p>
                <form id="configForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">AccessKey</label>
                            <input type="text" class="form-input" name="access_key" value="<?php echo htmlspecialchars($cfg['access_key']); ?>" placeholder="七牛 AccessKey" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label class="form-label">SecretKey</label>
                            <input type="password" class="form-input" name="secret_key" placeholder="<?php echo $sk_set ? '已设置（留空表示不修改）' : '七牛 SecretKey'; ?>" autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Bucket 空间名</label>
                            <input type="text" class="form-input" name="bucket" value="<?php echo htmlspecialchars($cfg['bucket']); ?>" placeholder="如 my-bucket">
                        </div>
                        <div class="form-group">
                            <label class="form-label">存储区域</label>
                            <select class="form-select" name="region">
                                <?php foreach ($regionOptions as $code => $name): ?>
                                    <option value="<?php echo $code; ?>" <?php if ($cfg['region'] === $code) echo 'selected'; ?>><?php echo $name; ?>（<?php echo $code; ?>）</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">绑定域名（CDN/加速域名）</label>
                            <input type="text" class="form-input" name="domain" value="<?php echo htmlspecialchars($cfg['domain']); ?>" placeholder="如 https://cdn.example.com（用于拼接文件访问 URL）">
                            <span class="form-hint">需以 http:// 或 https:// 开头，末尾不要带斜杠；留空则使用默认域名。</span>
                        </div>
                        <div class="form-group full">
                            <label class="checkbox-wrap">
                                <input type="checkbox" name="is_enabled" value="1" <?php if ($cfg['is_enabled'] === '1') echo 'checked'; ?>>
                                启用七牛存储（预留开关，供站点其他模块判断是否走七牛）
                            </label>
                        </div>
                    </div>
                    <div style="margin-top:20px; display:flex; gap:10px; flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> 保存配置</button>
                        <button type="button" class="btn btn-ghost" id="testBtn"><i class="fas fa-plug"></i> 测试连接</button>
                        <button type="button" class="btn btn-ghost" id="debugBtn" style="color:#c0707a;border-color:rgba(232,176,176,0.5);"><i class="fas fa-bug"></i> 签名调试</button>
                    </div>
                </form>
            </div>

            <!-- 上传卡片 -->
            <div class="card" id="uploadCard">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-cloud-upload-alt"></i> 文件上传</h3>
                </div>
                <p class="card-desc">支持图片、文档、压缩包等任意类型文件，自动加时间戳防重名。<?php if(!$configured): ?><span style="color:#c0707a;">请先完成并保存上方配置。</span><?php endif; ?></p>
                <div class="toolbar" style="margin-bottom:14px;">
                    <input type="text" class="form-input" id="uploadPrefix" placeholder="上传目录前缀（可选），如 img/">
                </div>
                <div class="upload-area" id="uploadArea">
                    <i class="fas fa-cloud-arrow-up"></i>
                    <div class="t">点击或拖拽文件到此处上传</div>
                    <div class="s">支持多文件上传 · 单文件受服务器 upload_max_filesize 限制</div>
                    <input type="file" id="fileInput" multiple style="display:none;">
                </div>
                <div class="upload-bar" id="uploadBar"></div>
            </div>

            <!-- 文件列表卡片 -->
            <div class="card data-card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-folder-open"></i> 文件管理</h3>
                </div>
                <div class="toolbar" style="margin-bottom:14px;">
                    <input type="text" class="form-input" id="searchPrefix" placeholder="按前缀筛选，如 img/">
                    <button class="btn btn-ghost btn-sm" onclick="loadFiles(true)"><i class="fas fa-search"></i> 查询</button>
                </div>
                <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>预览</th>
                                <th>文件名（Key）</th>
                                <th>大小</th>
                                <th>类型</th>
                                <th>更新时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="fileTbody">
                            <tr class="empty-row"><td colspan="6">加载中...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination" id="pagination"></div>
            </div>
        </div>
        <div class="footer"><p>© 2026 建湖梦玖信息技术工作室（个体工商户） · 管理后台系统 · 七牛云对象存储对接</p></div>
    </div>

<script>
function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}
document.querySelectorAll('.sidebar-menu a').forEach(function(link){
    link.addEventListener('click', function(){
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
    });
});

function showMsg(type, text){
    var box = document.getElementById('globalMsg');
    box.className = 'message show ' + type;
    document.getElementById('globalMsgText').textContent = text;
    if(type === 'success'){ setTimeout(function(){ box.classList.remove('show'); }, 3000); }
}

// ===== 配置保存 =====
document.getElementById('configForm').addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(this);
    fetch('qiniu.php?action=save_config', { method:'POST', body:fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
        showMsg(d.code === 200 ? 'success' : 'error', d.msg);
        if(d.code === 200 && fd.get('secret_key')){ document.querySelector('[name=secret_key]').value=''; }
    })
    .catch(function(){ showMsg('error', '网络错误'); });
});

// ===== 测试连接 =====
document.getElementById('testBtn').addEventListener('click', function(){
    var btn = this; btn.disabled = true; var old = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 测试中...';
    fetch('qiniu.php?action=test', { method:'POST' })
    .then(function(r){ return r.json(); })
    .then(function(d){ showMsg(d.code === 200 ? 'success' : 'error', d.msg); })
    .catch(function(){ showMsg('error', '网络错误'); })
    .finally(function(){ btn.disabled = false; btn.innerHTML = old; });
});

// ===== 签名调试 =====
document.getElementById('debugBtn').addEventListener('click', function(){
    var btn = this; btn.disabled = true; var old = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 调试中...';
    fetch('qiniu.php?action=debug', { method:'POST' })
    .then(function(r){ return r.json(); })
    .then(function(d){
        var data = d.data || {};
        var html = '<div style="font-family:monospace;font-size:12px;line-height:1.55;max-height:90vh;overflow:auto;padding:10px;">';

        html += '<div style="background:#e8f5e9;padding:8px;border-radius:6px;margin-bottom:10px;"><b>环境</b></div>';
        html += '<div>PHP: '+(data.php_version||'-')+'</div>';
        html += '<div>Region: '+(data.region||'-')+'  RS_Host: '+(data.rs_host||'-')+'  RSF_Host: '+(data.rsf_host||'-')+'</div>';
        html += '<div>AK: '+(data.ak||'-')+'  AK MD5: '+(data.ak_md5||'-')+'</div>';
        html += '<div>SK 长度: '+(data.sk_len||'-')+'  SK MD5: '+(data.sk_md5||'-')+'  字符: '+(data.sk_ok?'✅':'<span style="color:#c00;">⚠</span>')+'</div>';
        if(data.sk_issues && data.sk_issues.length) html += '<div style="color:#c00;">SK异常字符: '+data.sk_issues.join('; ')+'</div>';
        html += '<div>Bucket: '+(data.bucket||'-')+'</div>';
        if(data.body) html += '<div>Body参数: <code>'+data.body+'</code></div>';

        html += '<hr style="border:none;border-top:1px solid #ddd;margin:10px 0;">';
        html += '<div style="background:'+(data.ping_code===200?'#e8f5e9':'#ffebee')+';padding:8px;border-radius:6px;margin-bottom:10px;"><b>Ping 连通</b> (无鉴权) → HTTP '+(data.ping_code||0)+' '+(data.ping_code===200?'✅':'❌')+'</div>';
        if(data.ping_body) html += '<div>响应: '+data.ping_body+'</div>';

        html += '<hr style="border:none;border-top:1px solid #ddd;margin:10px 0;">';
        html += '<div style="background:'+(data.baidu_code===200?'#e8f5e9':'#ffebee')+';padding:8px;border-radius:6px;margin-bottom:10px;"><b>外网连通</b> (百度) → HTTP '+(data.baidu_code||0)+' '+(data.baidu_code===200?'✅':'❌')+'</div>';
        html += '<div>响应长度: '+(data.baidu_len||0)+' 字节</div>';

        html += '<hr style="border:none;border-top:1px solid #ddd;margin:10px 0;">';
        var ucOk = (data.uc_code===200);
        html += '<div style="background:'+(ucOk?'#e8f5e9':'#ffebee')+';padding:8px;border-radius:6px;margin-bottom:10px;"><b>UC 公开接口</b> (bucket区域查询, 无签名) → HTTP '+(data.uc_code||0)+' '+(ucOk?'✅':'❌')+'</div>';
        html += '<div>响应: '+(data.uc_body||'-')+'</div>';
        if(data.uc_code===631) {
            html += '<div style="color:#c00;margin-top:4px;">⚠ 631 = bucket不存在或不属于当前AK账号，请核对控制台账号与bucket归属</div>';
        }

        html += '<hr style="border:none;border-top:1px solid #ddd;margin:10px 0;">';
        html += '<div style="background:#f3e5f5;padding:8px;border-radius:6px;margin-bottom:10px;"><b>📋 Bucket 列表查询 (POST /buckets)</b></div>';
        if(data.bucket_results && data.bucket_results.length) {
            for(var bi=0;bi<data.bucket_results.length;bi++) {
                var br = data.bucket_results[bi];
                var brok = (br.result && br.result.code===200);
                html += '<div style="margin:6px 0;padding:6px;border-radius:4px;background:'+(brok?'#e8f5e9':'#fff0f0')+';">';
                html += '<div>['+br.host+'] '+br.auth+' → HTTP '+(br.result&&br.result.code||0)+' '+(brok?'✅':'❌')+'</div>';
                if(br.result && br.result.body) {
                    if(brok) {
                        try {
                            var buckets = JSON.parse(br.result.body);
                            if(buckets && buckets.length) {
                                html += '<div style="margin-top:4px;">共 '+buckets.length+' 个bucket:</div>';
                                html += '<div style="background:#fff;padding:4px;border-radius:4px;max-height:150px;overflow:auto;">';
                                for(var bj=0;bj<buckets.length;bj++) {
                                    var bn = buckets[bj];
                                    var isMatch = (typeof bn === 'string' ? bn : bn.name) === data.bucket;
                                    html += '<div style="padding:2px 4px;border-bottom:1px solid #eee;'+(isMatch?'background:#e8f5e9;font-weight:bold;':'')+'">';
                                    html += (typeof bn === 'string' ? bn : (bn.name||JSON.stringify(bn))) + (isMatch?' <span style="color:#1a7f37;">← 匹配当前配置</span>':'');
                                    html += '</div>';
                                }
                                html += '</div>';
                            } else {
                                html += '<div style="color:#c00;">⚠ 返回空列表，该AK下可能没有任何bucket</div>';
                            }
                        } catch(e) { html += '<div>'+br.result.body+'</div>'; }
                    } else {
                        html += '<div style="color:#900;">响应: '+br.result.body+'</div>';
                    }
                }
                html += '</div>';
            }
        }

        html += '<hr style="border:none;border-top:1px solid #ddd;margin:10px 0;">';
        html += '<div style="background:#e3f2fd;padding:8px;border-radius:6px;margin-bottom:10px;"><b>� 列举文件组合尝试 (POST /list, body参数)</b></div>';
        var anySuccess = false;
        if(data.list_results && data.list_results.length) {
            for(var i=0;i<data.list_results.length;i++) {
                var lr = data.list_results[i];
                var lrok = (lr.result && lr.result.code===200);
                if(lrok) anySuccess = true;
                html += '<div style="margin:6px 0;padding:8px;border-radius:4px;background:'+(lrok?'#e8f5e9':'#fff0f0')+';border:1px solid '+(lrok?'#a5d6a7':'#ef9a9a')+';">';
                html += '<div style="font-weight:bold;">第'+(i+1)+'组: ['+lr.host+'] / '+lr.auth+' → HTTP '+(lr.result&&lr.result.code||0)+' '+(lrok?'✅ 成功！':'❌')+'</div>';
                if(lr.signing) html += '<div style="margin-top:4px;">签名原文: <pre style="background:#263238;color:#eeffff;padding:4px 6px;border-radius:4px;overflow:auto;margin:4px 0;font-size:11px;word-break:break-all;white-space:pre-wrap;">'+JSON.stringify(lr.signing)+'\n\nHEX: '+lr.signingHex+'</pre></div>';
                if(lr.authHeader) html += '<div>Authorization: <code>'+lr.authHeader+'</code></div>';
                if(lr.result && lr.result.body) html += '<div style="color:'+(lrok?'#1a7f37':'#900')+';margin-top:4px;">响应: '+lr.result.body+'</div>';
                html += '</div>';
            }
        }
        if(anySuccess) {
            html += '<div style="background:#e8f5e9;padding:8px;border-radius:6px;margin-top:8px;"><b>🎉 至少一种组合验证成功！</b> 正常的 list/delete 操作会自动使用成功的组合。</div>';
        }

        html += '<hr style="border:none;border-top:1px solid #ddd;margin:10px 0;">';
        html += '<div style="background:#fff3e0;padding:8px;border-radius:6px;margin-bottom:10px;"><b>� cURL 详细</b> (针对ping请求)</div>';
        if(data.curl_err) html += '<div style="color:#c00;">cURL 错误: '+data.curl_err+'</div>';
        html += '<div>服务器IP: '+(data.primary_ip||'-')+'  本机出口IP: '+(data.local_ip||'-')+'  耗时: '+(data.total_time||0)+'s</div>';
        if(data.resp_header) {
            html += '<div style="margin-top:4px;">响应头:</div>';
            html += '<pre style="background:#f5f0f8;padding:6px;border-radius:4px;white-space:pre-wrap;font-size:11px;word-break:break-all;max-height:150px;overflow:auto;">'+data.resp_header+'</pre>';
        }

        html += '</div>';

        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;';
        var box = document.createElement('div');
        box.style.cssText = 'background:#fff;border-radius:12px;max-width:900px;width:100%;max-height:95vh;overflow:auto;box-shadow:0 8px 32px rgba(0,0,0,0.2);';
        box.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #eee;"><span style="font-weight:bold;color:#4a4556;">🔍 七牛签名深度调试（多域名×多鉴权）</span><button id="debugClose" style="border:none;background:#f3e8f7;border-radius:8px;padding:4px 12px;cursor:pointer;color:#885599;">关闭</button></div>'+html;
        overlay.appendChild(box);
        document.body.appendChild(overlay);
        var closeFn = function(){ document.body.removeChild(overlay); };
        overlay.addEventListener('click', function(e){ if(e.target===overlay) closeFn(); });
        box.querySelector('#debugClose').addEventListener('click', closeFn);
    })
    .catch(function(){ showMsg('error', '网络错误'); })
    .finally(function(){ btn.disabled = false; btn.innerHTML = old; });
});

// ===== 上传 =====
var uploadArea = document.getElementById('uploadArea');
var fileInput = document.getElementById('fileInput');
uploadArea.addEventListener('click', function(){ fileInput.click(); });
['dragenter','dragover'].forEach(function(ev){
    uploadArea.addEventListener(ev, function(e){ e.preventDefault(); uploadArea.classList.add('dragover'); });
});
['dragleave','drop'].forEach(function(ev){
    uploadArea.addEventListener(ev, function(e){ e.preventDefault(); uploadArea.classList.remove('dragover'); });
});
uploadArea.addEventListener('drop', function(e){
    if(e.dataTransfer && e.dataTransfer.files.length){ uploadFiles(e.dataTransfer.files); }
});
fileInput.addEventListener('change', function(){
    if(this.files.length){ uploadFiles(this.files); this.value = ''; }
});

function uploadFiles(files){
    var bar = document.getElementById('uploadBar');
    var prefix = document.getElementById('uploadPrefix').value.trim();
    var items = [];
    Array.from(files).forEach(function(f){
        var div = document.createElement('div');
        div.className = 'upload-item';
        div.innerHTML = '<i class="fas fa-file" style="color:var(--primary-dark);"></i><span class="name">'+escapeHtml(f.name)+'</span><span class="res" style="color:var(--text-muted);">上传中...</span>';
        bar.appendChild(div);
        items.push({ file:f, el:div });
    });

    items.forEach(function(it){
        var fd = new FormData();
        fd.append('file', it.file);
        if(prefix) fd.append('prefix', prefix);
        fetch('qiniu.php?action=upload', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            var res = it.el.querySelector('.res');
            if(d.code === 200){
                res.className = 'res ok';
                res.innerHTML = '<i class="fas fa-check"></i> <a href="'+escapeHtml(d.data.url)+'" target="_blank" style="color:#6fa886;text-decoration:none;">已上传</a>';
                loadFiles(true);
            }else{
                res.className = 'res err';
                res.textContent = d.msg || '失败';
            }
        })
        .catch(function(){
            var res = it.el.querySelector('.res');
            res.className = 'res err'; res.textContent = '网络错误';
        });
    });
}

// ===== 文件列表 =====
var currentMarker = '';
var nextMarker = '';
function loadFiles(reset){
    if(reset){ currentMarker = ''; }
    var prefix = document.getElementById('searchPrefix').value.trim();
    var url = 'qiniu.php?action=list_files&limit=50&prefix=' + encodeURIComponent(prefix) + '&marker=' + encodeURIComponent(currentMarker);
    var tbody = document.getElementById('fileTbody');
    if(reset){ tbody.innerHTML = '<tr class="empty-row"><td colspan="6">加载中...</td></tr>'; }
    fetch(url)
    .then(function(r){ return r.json(); })
    .then(function(d){
        if(d.code !== 200){
            tbody.innerHTML = '<tr class="empty-row"><td colspan="6">'+escapeHtml(d.msg)+'</td></tr>';
            document.getElementById('pagination').innerHTML = '';
            return;
        }
        var items = (d.data && d.data.items) || [];
        nextMarker = (d.data && d.data.marker) ? d.data.marker : '';
        if(!items.length){
            tbody.innerHTML = '<tr class="empty-row"><td colspan="6">暂无文件</td></tr>';
        }else{
            tbody.innerHTML = items.map(function(it){
                return '<tr>'+
                    '<td>'+renderThumb(it)+'</td>'+
                    '<td>'+escapeHtml(it.key)+'</td>'+
                    '<td>'+formatSize(it.fsize)+'</td>'+
                    '<td>'+escapeHtml(it.mimeType || '-')+'</td>'+
                    '<td>'+formatTime(it.putTime)+'</td>'+
                    '<td><div class="action-btns">'+
                        '<button class="btn btn-ghost btn-sm copy-btn" data-url="'+escapeHtml(it.url)+'"><i class="fas fa-copy"></i> 复制</button>'+
                        '<a class="btn btn-ghost btn-sm" href="'+escapeHtml(it.url)+'" target="_blank"><i class="fas fa-external-link-alt"></i> 打开</a>'+
                        '<button class="btn btn-danger btn-sm del-btn" data-key="'+escapeHtml(it.key)+'"><i class="fas fa-trash"></i> 删除</button>'+
                    '</div></td>'+
                '</tr>';
            }).join('');
        }
        renderPagination();
    })
    .catch(function(){
        tbody.innerHTML = '<tr class="empty-row"><td colspan="6">加载失败</td></tr>';
    });
}

function renderPagination(){
    var p = document.getElementById('pagination');
    var html = '';
    if(currentMarker){ html += '<button class="btn btn-ghost btn-sm" onclick="prevPage()"><i class="fas fa-chevron-left"></i> 上一页</button>'; }
    if(nextMarker){ html += '<button class="btn btn-ghost btn-sm" onclick="nextPage()"><i class="fas fa-chevron-right"></i> 下一页</button>'; }
    p.innerHTML = html;
}
function nextPage(){ currentMarker = nextMarker; loadFiles(false); }
function prevPage(){ /* 简单实现：重置到首页 */ currentMarker=''; loadFiles(true); }

document.getElementById('fileTbody').addEventListener('click', function(e){
    var copy = e.target.closest('.copy-btn');
    if(copy){
        var url = copy.getAttribute('data-url');
        navigator.clipboard.writeText(url).then(function(){ showMsg('success','链接已复制'); }).catch(function(){
            var ta=document.createElement('textarea'); ta.value=url; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');showMsg('success','链接已复制');}catch(_){showMsg('error','复制失败');} document.body.removeChild(ta);
        });
        return;
    }
    var del = e.target.closest('.del-btn');
    if(del){
        var key = del.getAttribute('data-key');
        if(!confirm('确认删除文件：\n'+key+' ?\n该操作不可恢复。')) return;
        var fd = new FormData(); fd.append('key', key);
        fetch('qiniu.php?action=delete_file', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            showMsg(d.code === 200 ? 'success':'error', d.msg);
            if(d.code === 200) loadFiles(true);
        })
        .catch(function(){ showMsg('error','网络错误'); });
    }
});

function renderThumb(it){
    var mime = it.mimeType || '';
    var url = it.url || '';
    if(mime.indexOf('image/') === 0){
        return '<img class="file-thumb" src="'+escapeHtml(url)+'" loading="lazy" onerror="this.outerHTML=\'<div class=file-icon><i class=\\\'fas fa-image\\\'></i></div>\'">';
    }
    var icon = 'fa-file';
    if(mime.indexOf('video/') === 0) icon = 'fa-file-video';
    else if(mime.indexOf('audio/') === 0) icon = 'fa-file-audio';
    else if(mime.indexOf('pdf') >= 0) icon = 'fa-file-pdf';
    else if(mime.indexOf('zip') >= 0 || mime.indexOf('compressed') >= 0) icon = 'fa-file-archive';
    else if(mime.indexOf('text') >= 0) icon = 'fa-file-alt';
    return '<div class="file-icon"><i class="fas '+icon+'"></i></div>';
}
function formatSize(b){
    b = parseInt(b,10) || 0;
    if(b < 1024) return b + ' B';
    if(b < 1048576) return (b/1024).toFixed(1) + ' KB';
    if(b < 1073741824) return (b/1048576).toFixed(1) + ' MB';
    return (b/1073741824).toFixed(2) + ' GB';
}
function formatTime(pt){
    // 七牛 putTime 单位 100 纳秒
    var ms = parseInt(pt,10) / 10000;
    if(!ms) return '-';
    var d = new Date(ms);
    function p(n){ return n<10?'0'+n:n; }
    return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());
}
function escapeHtml(s){
    s = (s === null || s === undefined) ? '' : String(s);
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

// 初始加载
loadFiles(true);
</script>
</body>
</html>
