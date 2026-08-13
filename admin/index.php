<?php
require_once 'config.php';
checkAdminLogin();

// 获取统计数据
$admin = getCurrentAdmin();

// 用户总数
$userCount = 0;
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM user_info");
if ($result && $row = mysqli_fetch_assoc($result)) {
    $userCount = $row['total'];
}

// 留言总数
$commentCount = 0;
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM comments");
if ($result && $row = mysqli_fetch_assoc($result)) {
    $commentCount = $row['total'];
}

// 社区动态总数
$communityCount = 0;
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM community_posts WHERE status = 1");
if ($result && $row = mysqli_fetch_assoc($result)) {
    $communityCount = $row['total'];
}

// 追番总数
$bangumiCount = 0;
$bgResult = @mysqli_query($conn, "SELECT COUNT(*) as total FROM bangumi_follow");
if ($bgResult && $bgRow = mysqli_fetch_assoc($bgResult)) {
    $bangumiCount = $bgRow['total'];
}

// 安全统计（仅超管显示，避免无谓查询）
$secThreatCount = 0;
$secBlacklistCount = 0;
if (isSuperAdmin()) {
    $t1h = date('Y-m-d H:i:s', time() - 3600);
    $secR = @mysqli_query($conn, "SELECT COUNT(*) c FROM sec_access_log WHERE status=1 AND create_time>='$t1h'");
    if ($secR && $r = mysqli_fetch_row($secR)) $secThreatCount = (int)$r[0];
    $secB = @mysqli_query($conn, "SELECT COUNT(*) c FROM sec_ip_blacklist WHERE expire_time IS NULL OR expire_time>=NOW()");
    if ($secB && $r = mysqli_fetch_row($secB)) $secBlacklistCount = (int)$r[0];
}

// 管理员总数
$adminCount = 0;
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM admin_users");
if ($result && $row = mysqli_fetch_assoc($result)) {
    $adminCount = $row['total'];
}

// 今日新注册用户
$todayUsers = 0;
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM user_info WHERE DATE(create_time) = CURDATE()");
if ($result && $row = mysqli_fetch_assoc($result)) {
    $todayUsers = $row['total'];
}

// 今日新留言
$todayComments = 0;
$result = mysqli_query($conn, "SELECT COUNT(*) as total FROM comments WHERE DATE(create_time) = CURDATE()");
if ($result && $row = mysqli_fetch_assoc($result)) {
    $todayComments = $row['total'];
}

// 最近留言
$recentComments = [];
$result = mysqli_query($conn, "SELECT id, nickname, content, create_time FROM comments ORDER BY id DESC LIMIT 5");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $recentComments[] = $row;
    }
}

// 最近注册用户
$recentUsers = [];
$result = mysqli_query($conn, "SELECT id, username, email FROM user_info ORDER BY id DESC LIMIT 5");
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $recentUsers[] = $row;
    }
}

// 获取近7天注册数据
$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i day"));
    $dayName = date('m-d', strtotime("-$i day"));
    
    $result = mysqli_query($conn, "SELECT COUNT(*) as total FROM user_info WHERE DATE(create_time) = '$date'");
    $count = 0;
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $count = $row['total'];
    }
    $chartData[] = ['date' => $dayName, 'count' => $count];
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="zh-CN" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台管理系统 - 仪表盘</title>
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

        .menu-item {
            position: relative;
        }

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

        /* 页面内容 */
        .page-content {
            padding: 24px;
        }

        /* 统计卡片 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-light);
        }

        .stat-card:hover {
            border-color: rgba(201, 169, 212, 0.5);
            transform: translateY(-4px);
            box-shadow: var(--shadow-hover);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .stat-icon.blue {
            background: rgba(201, 169, 212, 0.2);
            color: var(--primary-dark);
        }

        .stat-icon.green {
            background: rgba(168, 213, 186, 0.2);
            color: var(--success);
        }

        .stat-icon.purple {
            background: rgba(232, 197, 208, 0.2);
            color: var(--secondary);
        }

        .stat-icon.orange {
            background: rgba(232, 212, 168, 0.2);
            color: var(--warning);
        }

        .stat-trend {
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 20px;
        }

        .stat-trend.up {
            background: rgba(168, 213, 186, 0.2);
            color: #7ab893;
        }

        .stat-trend.down {
            background: rgba(232, 176, 176, 0.2);
            color: var(--danger);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-secondary);
        }

        /* 每日一言卡片 */
        .quote-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-light);
            background-image: 
                linear-gradient(135deg, rgba(201, 169, 212, 0.05) 0%, rgba(232, 197, 208, 0.05) 100%);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .card-actions {
            display: flex;
            gap: 8px;
        }

        .card-action-btn {
            padding: 6px 12px;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: 12px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .card-action-btn:hover {
            border-color: var(--primary);
            color: var(--primary-dark);
        }

        .card-action-btn.active {
            background: rgba(201, 169, 212, 0.2);
            border-color: var(--primary);
            color: var(--primary-dark);
        }

        /* 每日一言内容 */
        .quote-content {
            padding: 30px 20px;
            text-align: center;
            position: relative;
        }

        .quote-icon {
            font-size: 48px;
            color: rgba(201, 169, 212, 0.3);
            margin-bottom: 20px;
            animation: quoteFloat 3s ease-in-out infinite;
        }

        .quote-text {
            font-size: 18px;
            line-height: 1.8;
            color: var(--text-primary);
            font-style: italic;
            margin-bottom: 24px;
            min-height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .quote-author {
            font-size: 14px;
            color: var(--text-secondary);
            letter-spacing: 2px;
        }

        .refresh-btn {
            width: 36px;
            height: 36px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .refresh-btn:hover {
            transform: rotate(180deg);
        }

        @keyframes quoteFloat {
            0%, 100% { opacity: 0.3; transform: translateY(0); }
            50% { opacity: 0.5; transform: translateY(-5px); }
        }

        /* 数据表格 */
        .data-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-light);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: rgba(201, 169, 212, 0.08);
            padding: 16px 20px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border-color);
        }

        .data-table td {
            padding: 16px 20px;
            font-size: 14px;
            color: var(--text-primary);
            border-bottom: 1px solid rgba(201, 169, 212, 0.15);
        }

        .data-table tr:hover {
            background: rgba(201, 169, 212, 0.08);
        }

        .content-preview {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* 底部信息 */
        .footer {
            text-align: center;
            padding: 24px;
            color: var(--text-muted);
            font-size: 12px;
        }

        /* 响应式 */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: 0;
                top: 0;
                bottom: 0;
                width: 260px;
                transform: translateX(-100%);
                transition: transform 0.3s ease-out;
                z-index: 1600;
                background: rgba(255, 255, 255, 0.95);
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                box-shadow: 4px 0 20px rgba(201, 169, 212, 0.2);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .sidebar-logo .logo-text {
                display: block;
            }

            .menu-item a span:not(.icon) {
                display: block;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- 移动端菜单按钮 -->
    <div class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </div>
    
    <!-- 侧边栏遮罩 -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    
    <!-- 侧边栏 -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <div class="logo-icon">
                    <img src="./logo.png" alt="Logo" onerror="this.style.display='none';this.parentElement.innerHTML='<div style=\'width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:16px;color:#c9a9d4;background:linear-gradient(135deg, #f3e8f7, #f9f0f3);\'><i class=\'fas fa-crown\'></i></div>';" />
                </div>
                <span class="logo-text">真昼小破站</span>
            </div>
        </div>
        
        <div class="sidebar-menu">
            <div class="menu-item">
                <a href="index.php" class="active">
                    <i class="fas fa-chart-pie icon"></i>
                    <span>仪表盘</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="users.php">
                    <i class="fas fa-users icon"></i>
                    <span>用户管理</span>
                    <span class="badge"><?php echo $userCount; ?></span>
                </a>
            </div>
            <div class="menu-item">
                <a href="community.php">
                    <i class="fas fa-comments icon"></i>
                    <span>社区管理</span>
                    <span class="badge"><?php echo $communityCount; ?></span>
                </a>
            </div>
            <div class="menu-item">
                <a href="avatar_frames.php">
                    <i class="fas fa-circle-notch icon"></i>
                    <span>头像框管理</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="comments.php">
                    <i class="fas fa-comment-dots icon"></i>
                    <span>留言管理</span>
                    <span class="badge"><?php echo $commentCount; ?></span>
                </a>
            </div>
            <div class="menu-item">
                <a href="bangumi.php">
                    <i class="fas fa-tv icon"></i>
                    <span>追番管理</span>
                    <span class="badge"><?php echo $bangumiCount; ?></span>
                </a>
            </div>
            <div class="menu-item">
                <a href="reports.php">
                    <i class="fas fa-flag icon"></i>
                    <span>举报处理</span>
                </a>
            </div>
            <?php if (isSuperAdmin()): ?>
            <div class="menu-item">
                <a href="admins.php">
                    <i class="fas fa-shield-alt icon"></i>
                    <span>管理员管理</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="security.php">
                    <i class="fas fa-user-shield icon"></i>
                    <span>安全中心</span>
                    <?php if ($secThreatCount > 0 || $secBlacklistCount > 0): ?>
                    <span class="badge"><?php echo $secThreatCount + $secBlacklistCount; ?></span>
                    <?php endif; ?>
                </a>
            </div>
            <div class="menu-item">
                <a href="qiniu.php">
                    <i class="fas fa-cloud icon"></i>
                    <span>七牛存储</span>
                </a>
            </div>
            <?php endif; ?>
            <div class="menu-item">
                <a href="qq_config.php">
                    <i class="fas fa-qrcode icon"></i>
                    <span>QQ登录配置</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt icon"></i>
                    <span>退出登录</span>
                </a>
            </div>
        </div>
    </div>

    <!-- 主内容区 -->
    <div class="main-content">
        <!-- 顶部导航栏 -->
        <div class="header">
            <div class="header-left">
                <h1 class="header-title">仪表盘</h1>
                <div class="header-breadcrumb">
                    <a href="#">首页</a>
                    <span>/</span>
                    <span>仪表盘</span>
                </div>
            </div>
            
            <div class="header-right">
                <div class="header-action" title="消息通知">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="header-action" title="搜索">
                    <i class="fas fa-search"></i>
                </div>
                <div class="header-action" title="设置">
                    <i class="fas fa-cog"></i>
                </div>
            </div>
        </div>

        <!-- 页面内容 -->
        <div class="page-content">
            <!-- 统计卡片 -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon blue">
                            <i class="fas fa-users"></i>
                        </div>
                        <span class="stat-trend up">↑ 12%</span>
                    </div>
                    <div class="stat-value"><?php echo $userCount; ?></div>
                    <div class="stat-label">总用户数</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon green">
                            <i class="fas fa-comments"></i>
                        </div>
                        <span class="stat-trend up">↑ 8%</span>
                    </div>
                    <div class="stat-value"><?php echo $commentCount; ?></div>
                    <div class="stat-label">总留言数</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon purple">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <span class="stat-trend up">↑ 5%</span>
                    </div>
                    <div class="stat-value"><?php echo $adminCount; ?></div>
                    <div class="stat-label">管理员数</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon orange">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <span class="stat-trend up">今日新增</span>
                    </div>
                    <div class="stat-value"><?php echo $todayUsers; ?> / <?php echo $todayComments; ?></div>
                    <div class="stat-label">用户 / 留言</div>
                </div>
            </div>

            <!-- 每日一言卡片 -->
            <div class="quote-card">
                <div class="card-header">
                    <h3 class="card-title">每日一言</h3>
                    <div class="card-actions">
                        <button class="card-action-btn refresh-btn" onclick="refreshQuote()">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="quote-content">
                    <div class="quote-icon">
                        <i class="fas fa-quote-left"></i>
                    </div>
                    <div class="quote-text" id="hitokoto-text">正在获取每日一言...</div>
                    <div class="quote-author" id="hitokoto-author">-- 加载中</div>
                </div>
            </div>

            <!-- 数据表格 -->
            <div class="data-card">
                <div class="card-header">
                    <h3 class="card-title">最近动态</h3>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>类型</th>
                            <th>用户名</th>
                            <th>内容/邮箱</th>
                            <th>时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentUsers as $user): ?>
                            <tr>
                                <td><span style="color: var(--primary); font-size: 14px;">注册</span></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>-</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($recentComments as $comment): ?>
                            <tr>
                                <td><span style="color: var(--success); font-size: 14px;">留言</span></td>
                                <td><?php echo htmlspecialchars($comment['nickname']); ?></td>
                                <td class="content-preview" title="<?php echo htmlspecialchars($comment['content']); ?>">
                                    <?php echo htmlspecialchars($comment['content']); ?>
                                </td>
                                <td><?php echo date('m-d H:i', strtotime($comment['create_time'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 页脚 -->
        <div class="footer">
            <p>© 2026 建湖梦玖信息技术工作室（个体工商户） · 管理后台系统</p>
        </div>
    </div>

    <script>
        // 获取每日一言
        function refreshQuote() {
            const textElement = document.getElementById('hitokoto-text');
            const authorElement = document.getElementById('hitokoto-author');
            
            fetch('https://v1.hitokoto.cn/?c=b')
                .then(response => response.json())
                .then(data => {
                    textElement.textContent = data.hitokoto;
                    authorElement.textContent = '-- ' + (data.from || '匿名');
                })
                .catch(error => {
                    textElement.textContent = '获取失败，请点击刷新重试';
                    authorElement.textContent = '-- 系统';
                });
        }

        // 页面加载时获取
        document.addEventListener('DOMContentLoaded', refreshQuote);

        // 移动端侧边栏切换
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        }

        // 点击侧边栏链接时关闭侧边栏（移动端）
        document.querySelectorAll('.sidebar-menu a').forEach(link => {
            link.addEventListener('click', () => {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
            });
        });
    </script>
</body>
</html>
