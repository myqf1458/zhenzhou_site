<?php
require_once 'config.php';
checkAdminLogin();

$admin = getCurrentAdmin();
$isSuper = isSuperAdmin();

$configFile = __DIR__ . '/../qq_oauth_config.php';
$qqConfig = [
    'app_id' => '',
    'app_key' => '',
    'redirect_uri' => '',
    'enabled' => 0
];

if (file_exists($configFile)) {
    $data = include $configFile;
    if (is_array($data)) $qqConfig = array_merge($qqConfig, $data);
}

$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_qq_config') {
    // CSRF 校验已由 admin_security.php::checkCsrfOnPost() 在检测到 _sec_csrf 字段时自动完成（严格校验）
    // 此处无需重复调用，否则 token 会被 verifyCsrf 内部重置导致二次校验失败
    if (!$isSuper) {
        $msg = '仅超级管理员可修改配置';
        $msgType = 'error';
    } else {
        $appId = trim($_POST['app_id'] ?? '');
        $appKey = trim($_POST['app_key'] ?? '');
        $redirectUri = trim($_POST['redirect_uri'] ?? '');
        $enabled = intval($_POST['enabled'] ?? 0);

        if (empty($appId)) {
            $msg = 'App ID 不能为空';
            $msgType = 'error';
        } elseif (empty($appKey)) {
            $msg = 'App Key 不能为空';
            $msgType = 'error';
        } elseif (strlen($appId) > 50) {
            $msg = 'App ID 过长';
            $msgType = 'error';
        } elseif (strlen($appKey) > 100) {
            $msg = 'App Key 过长';
            $msgType = 'error';
        } else {
            $configContent = "<?php\n";
            $configContent .= "// QQ 登录配置 - 由后台管理系统生成\n";
            $configContent .= "return [\n";
            $configContent .= "    'app_id' => " . var_export($appId, true) . ",\n";
            $configContent .= "    'app_key' => " . var_export($appKey, true) . ",\n";
            $configContent .= "    'redirect_uri' => " . var_export($redirectUri, true) . ",\n";
            $configContent .= "    'enabled' => " . ($enabled ? '1' : '0') . ",\n";
            $configContent .= "];\n";

            if (@file_put_contents($configFile, $configContent, LOCK_EX)) {
                if (function_exists('chmod')) @chmod($configFile, 0600);
                $qqConfig = ['app_id' => $appId, 'app_key' => $appKey, 'redirect_uri' => $redirectUri, 'enabled' => $enabled];
                SecGuard::writeLog(1, '保存QQ登录配置', "AppID:{$appId}");
                $msg = '配置保存成功';
                $msgType = 'success';
            } else {
                $msg = '配置文件写入失败，请检查目录权限';
                $msgType = 'error';
            }
        }
    }
}

$siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/';
$defaultRedirect = $siteUrl . 'qq_callback.php';
$currentRedirect = $qqConfig['redirect_uri'] ?: $defaultRedirect;
?>
<!DOCTYPE html>
<html lang="zh-CN" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QQ 登录配置 - 后台管理系统</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="common.css">
    <style>
        :root {
            --primary: #c9a9d4;
            --primary-dark: #b892c2;
            --primary-light: #f3e8f7;
            --secondary: #e8c5d0;
            --secondary-light: #f9f0f3;
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
        }

        /* 侧边栏 */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-right: 1px solid var(--border-color);
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 24px;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-logo .logo-icon {
            width: 36px;
            height: 36px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(201, 169, 212, 0.3);
            border: 2px solid rgba(201, 169, 212, 0.3);
        }

        .sidebar-logo .logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .sidebar-logo .logo-text {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .sidebar-menu {
            flex: 1;
            padding: 16px 0;
            overflow-y: auto;
        }

        .menu-item { position: relative; }

        .menu-item a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 24px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            border-radius: 0 12px 12px 0;
        }

        .menu-item a:hover {
            background: rgba(201, 169, 212, 0.1);
            color: var(--text-primary);
            border-left-color: rgba(201, 169, 212, 0.5);
        }

        .menu-item a.active {
            background: rgba(201, 169, 212, 0.15);
            color: var(--primary-dark);
            border-left-color: var(--primary);
        }

        .menu-item .icon {
            font-size: 17px;
            width: 20px;
            text-align: center;
        }

        .menu-item .badge {
            margin-left: auto;
            padding: 2px 8px;
            background: rgba(232, 176, 176, 0.2);
            color: var(--danger);
            font-size: 12px;
            border-radius: 10px;
        }

        /* 主内容区 */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        /* 顶部导航栏 */
        .header {
            height: var(--header-height);
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header-title {
            font-size: 18px;
            font-weight: 600;
        }

        .header-breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .header-breadcrumb a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color 0.2s;
        }

        .header-breadcrumb a:hover {
            color: var(--primary-dark);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-action {
            width: 36px;
            height: 36px;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .header-action:hover {
            background: rgba(201, 169, 212, 0.15);
            border-color: var(--primary);
            color: var(--primary-dark);
            transform: translateY(-1px);
        }

        .header-right .user-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-left: 6px;
            font-size: 13px;
            color: var(--text-primary);
        }

        /* 页面内容 */
        .page-content {
            padding: 24px;
        }

        /* QQ 配置页专用样式 */
        .config-wrap { max-width: 760px; margin: 0 auto; }
        .config-card {
            background: var(--bg-card);
            backdrop-filter: blur(14px) saturate(160%);
            -webkit-backdrop-filter: blur(14px) saturate(160%);
            border-radius: 18px;
            border: 1px solid var(--border-color);
            padding: 28px 32px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-light);
        }
        .config-card h2 {
            font-size: 18px;
            color: var(--text-primary);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }
        .config-card h2 i { color: var(--primary); }
        .status-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            margin-left: auto;
        }
        .status-badge.active { background: rgba(168,213,186,.3); color: #4a7a5a; }
        .status-badge.inactive { background: rgba(232,176,176,.3); color: #a85050; }
        .config-guide {
            background: rgba(201,169,212,.12);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
        }
        .config-guide h3 { font-size: 14px; color: var(--primary-dark); margin: 0 0 10px; font-weight: 600; }
        .config-guide ol { padding-left: 20px; margin: 0; color: var(--text-secondary); font-size: 13px; line-height: 1.9; }
        .config-guide a { color: var(--primary-dark); text-decoration: none; }
        .config-guide a:hover { text-decoration: underline; }
        .form-group { margin-bottom: 20px; }
        .form-group > label {
            display: block;
            font-size: 13px;
            color: var(--text-primary);
            margin-bottom: 6px;
            font-weight: 600;
        }
        .form-group input[type=text] {
            width: 100%;
            height: 44px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            background: var(--bg-input);
            padding: 0 14px;
            font-size: 14px;
            color: var(--text-primary);
            outline: none;
            transition: all .2s;
            font-family: inherit;
        }
        .form-group input[type=text]:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(201,169,212,.2);
            background: #fff;
        }
        .form-group input[type=text]::placeholder { color: var(--text-muted); }
        .form-hint { display: block; font-size: 12px; color: var(--text-muted); margin-top: 6px; }
        .checkbox-group { display: inline-flex; align-items: center; gap: 8px; }
        .checkbox-group input[type=checkbox] {
            width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer;
        }
        .checkbox-group label { margin: 0; font-weight: 400; cursor: pointer; }
        .form-actions { display: flex; gap: 12px; margin-top: 24px; }
        .btn {
            padding: 10px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all .2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: inherit;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            box-shadow: 0 4px 14px rgba(201,169,212,.3);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 22px rgba(201,169,212,.38); }
        .btn-secondary {
            background: rgba(201,169,212,.15);
            color: var(--primary-dark);
            border: 1px solid var(--border-color);
        }
        .btn-secondary:hover { background: rgba(201,169,212,.25); }
        .callback-display {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--bg-input);
            border: 1px dashed var(--border-color);
            border-radius: 12px;
            padding: 14px 16px;
        }
        .callback-display code {
            flex: 1;
            font-family: ui-monospace, 'SF Mono', Consolas, monospace;
            font-size: 13px;
            color: var(--text-primary);
            word-break: break-all;
        }
        .btn-copy {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            width: 36px;
            height: 36px;
            cursor: pointer;
            color: var(--text-secondary);
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .btn-copy:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 18px;
            font-size: 14px;
        }
        .alert-success { background: rgba(168,213,186,.22); color: #3d6a4f; border: 1px solid rgba(168,213,186,.4); }
        .alert-error { background: rgba(232,176,176,.22); color: #8a4040; border: 1px solid rgba(232,176,176,.4); }
        .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }

        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: 0; top: 0; bottom: 0;
                width: 260px;
                transform: translateX(-100%);
                transition: transform .3s ease-out;
                z-index: 1600;
                background: rgba(255,255,255,.95);
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                box-shadow: 4px 0 20px rgba(201,169,212,.2);
            }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; width: 100%; }
            .sidebar-logo .logo-text { display: block; }
            .menu-item a span:not(.icon) { display: block; }
            .config-wrap { padding: 0; }
            .config-card { padding: 20px; border-radius: 14px; }
            .form-actions { flex-direction: column; }
            .btn { justify-content: center; }
            .page-content { padding: 16px; }
        }
    </style>
</head>
<body>
    <div class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </div>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <div class="logo-icon">
                    <img src="../img/5.jpg" alt="logo">
                </div>
                <div class="logo-text">真昼小破站</div>
            </div>
        </div>
        <div class="sidebar-menu">
            <div class="menu-item"><a href="index.php"><i class="fas fa-chart-pie icon"></i><span>仪表盘</span></a></div>
            <div class="menu-item"><a href="users.php"><i class="fas fa-users icon"></i><span>用户管理</span></a></div>
            <div class="menu-item"><a href="community.php"><i class="fas fa-comments icon"></i><span>社区管理</span></a></div>
            <div class="menu-item"><a href="avatar_frames.php"><i class="fas fa-circle-notch icon"></i><span>头像框管理</span></a></div>
            <div class="menu-item"><a href="comments.php"><i class="fas fa-comment-dots icon"></i><span>留言管理</span></a></div>
            <div class="menu-item"><a href="bangumi.php"><i class="fas fa-tv icon"></i><span>追番管理</span></a></div>
            <div class="menu-item"><a href="reports.php"><i class="fas fa-flag icon"></i><span>举报处理</span></a></div>
            <?php if ($isSuper): ?>
            <div class="menu-item"><a href="admins.php"><i class="fas fa-shield-alt icon"></i><span>管理员管理</span></a></div>
            <div class="menu-item"><a href="security.php"><i class="fas fa-user-shield icon"></i><span>安全中心</span></a></div>
            <div class="menu-item"><a href="qiniu.php"><i class="fas fa-cloud icon"></i><span>七牛存储</span></a></div>
            <?php endif; ?>
            <div class="menu-item"><a href="qq_config.php" class="active"><i class="fas fa-qrcode icon"></i><span>QQ登录配置</span></a></div>
            <div class="menu-item"><a href="logout.php"><i class="fas fa-sign-out-alt icon"></i><span>退出登录</span></a></div>
        </div>
    </div>

    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1 class="header-title">QQ 登录配置</h1>
                <div class="header-breadcrumb">
                    <a href="index.php">首页</a>
                    <span>/</span>
                    <span>QQ 登录配置</span>
                </div>
            </div>
            <div class="header-right">
                <div class="header-action" style="width:auto;padding:0 12px;gap:6px">
                    <i class="fas fa-user-circle"></i>
                    <span class="user-label"><?php echo htmlspecialchars($admin['nickname'] ?? $admin['username']); ?></span>
                </div>
            </div>
        </div>

        <div class="page-content">
            <div class="config-wrap">
                <?php if ($msg): ?>
                <div class="alert alert-<?php echo $msgType === 'error' ? 'error' : 'success'; ?>">
                    <?php echo htmlspecialchars($msg); ?>
                </div>
                <?php endif; ?>

                <div class="config-card">
                    <div class="card-header">
                        <h2><i class="fas fa-qrcode"></i> QQ 互联应用配置</h2>
                        <span class="status-badge <?php echo $qqConfig['enabled'] ? 'active' : 'inactive'; ?>">
                            <?php echo $qqConfig['enabled'] ? '已启用' : '未启用'; ?>
                        </span>
                    </div>

                    <div class="config-guide">
                        <h3>📝 配置步骤</h3>
                        <ol>
                            <li>前往 <a href="https://connect.qq.com/" target="_blank" rel="noopener">QQ 互联开放平台</a> 注册并登录</li>
                            <li>创建"网站应用"，填写站点信息</li>
                            <li>将下方的回调地址填入 QQ 互联后台</li>
                            <li>复制 App ID 和 App Key 到下方表单</li>
                            <li>保存配置并启用</li>
                        </ol>
                    </div>

                    <form method="POST" id="qqConfigForm">
                        <?php echo SecGuard::csrfField(); ?>
                        <input type="hidden" name="action" value="save_qq_config">

                        <div class="form-group">
                            <label>回调地址（Redirect URI）</label>
                            <input type="text" name="redirect_uri" value="<?php echo htmlspecialchars($currentRedirect); ?>" placeholder="https://yourdomain.com/qq_callback.php">
                            <small class="form-hint">请将此地址完整填入 QQ 互联后台的回调地址栏</small>
                        </div>

                        <div class="form-group">
                            <label>App ID</label>
                            <input type="text" name="app_id" value="<?php echo htmlspecialchars($qqConfig['app_id']); ?>" placeholder="如：101234567" maxlength="50">
                            <small class="form-hint">QQ 互联后台分配的应用 ID</small>
                        </div>

                        <div class="form-group">
                            <label>App Key</label>
                            <input type="text" name="app_key" value="<?php echo htmlspecialchars($qqConfig['app_key']); ?>" placeholder="如：xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" maxlength="100">
                            <small class="form-hint">QQ 互联后台分配的应用密钥，请妥善保管</small>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" name="enabled" id="enableQq" value="1" <?php echo $qqConfig['enabled'] ? 'checked' : ''; ?>>
                                <label for="enableQq">启用 QQ 登录</label>
                            </div>
                            <small class="form-hint">启用后登录/注册页面将显示 QQ 登录入口</small>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> 保存配置</button>
                            <?php if ($qqConfig['enabled'] && !empty($qqConfig['app_id'])): ?>
                            <a href="../qq_login.php?test=1" target="_blank" class="btn btn-secondary"><i class="fas fa-external-link-alt"></i> 测试登录</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="config-card">
                    <h2><i class="fas fa-link"></i> 当前回调地址</h2>
                    <div style="height:12px"></div>
                    <div class="callback-display">
                        <code id="callbackUrl"><?php echo htmlspecialchars($currentRedirect); ?></code>
                        <button class="btn-copy" onclick="copyCallbackUrl()" title="复制">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    <p class="form-hint" style="margin-top:12px">
                        ⚠️ 回调地址必须与 QQ 互联后台配置完全一致，包括协议（http/https）和路径。
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar(){
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        }
        function copyCallbackUrl(){
            var text = document.getElementById('callbackUrl').textContent;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text)
                    .then(function(){ alert('回调地址已复制'); })
                    .catch(function(){ fallbackCopy(text); });
            } else {
                fallbackCopy(text);
            }
        }
        function fallbackCopy(text){
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                alert('回调地址已复制');
            } catch(e) {
                alert('复制失败，请手动复制');
            }
            document.body.removeChild(ta);
        }
    </script>
</body>
</html>