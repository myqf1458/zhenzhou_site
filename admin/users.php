<?php
require_once 'config.php';
checkAdminLogin();

$admin = getCurrentAdmin();
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// 角色定义
$roles = [
    'user' => '普通用户',
    'moderator' => '版主',
    'admin' => '管理员'
];

// 检查并添加 role 字段
$result = mysqli_query($conn, "SHOW COLUMNS FROM user_info LIKE 'role'");
if (!$result || mysqli_num_rows($result) == 0) {
    mysqli_query($conn, "ALTER TABLE user_info ADD COLUMN role VARCHAR(20) DEFAULT 'user'");
    // 更新已有用户为普通用户
    mysqli_query($conn, "UPDATE user_info SET role = 'user' WHERE role IS NULL");
}

// 检查并添加 nickname 字段
$result = mysqli_query($conn, "SHOW COLUMNS FROM user_info LIKE 'nickname'");
if (!$result || mysqli_num_rows($result) == 0) {
    mysqli_query($conn, "ALTER TABLE user_info ADD COLUMN nickname VARCHAR(50) DEFAULT ''");
    // 将用户名作为默认昵称
    mysqli_query($conn, "UPDATE user_info SET nickname = username WHERE nickname = ''");
}

// 检查并添加 gender 字段
$result = mysqli_query($conn, "SHOW COLUMNS FROM user_info LIKE 'gender'");
if (!$result || mysqli_num_rows($result) == 0) {
    mysqli_query($conn, "ALTER TABLE user_info ADD COLUMN gender VARCHAR(10) DEFAULT 'unknown'");
}

// 删除用户
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    SecGuard::verifyCsrf(true);
    $userId = intval($_POST['id']);
    if ($userId <= 0) {
        $error = '无效的用户ID';
    } else {
        $stmt = mysqli_prepare($conn, "DELETE FROM comments WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $stmt = mysqli_prepare($conn, "DELETE FROM user_info WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        if (mysqli_stmt_execute($stmt)) {
            SecGuard::writeLog(1, '删除用户', "ID:{$userId}");
            header('Location: users.php?msg=delete_success');
            exit;
        } else {
            error_log('users.php delete failed: ' . mysqli_error($conn));
            $error = '删除失败，请稍后重试';
        }
    }
}

// 修改用户角色
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_role') {
    SecGuard::verifyCsrf(true);
    $userId = intval($_POST['id']);
    $role = $_POST['role'] ?? 'user';
    if ($userId <= 0) {
        $error = '无效的用户ID';
    } elseif (!isset($roles[$role])) {
        $error = '无效的角色';
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE user_info SET role = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $role, $userId);
        if (mysqli_stmt_execute($stmt)) {
            SecGuard::writeLog(1, '修改用户角色', "ID:{$userId} Role:{$role}");
            header('Location: users.php?msg=role_success');
            exit;
        } else {
            error_log('users.php update_role failed: ' . mysqli_error($conn));
            $error = '修改角色失败，请稍后重试';
        }
    }
}

// 编辑用户信息
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    SecGuard::verifyCsrf(true);
    $userId = intval($_POST['id']);
    $username = trim($_POST['username'] ?? '');
    $nickname = trim($_POST['nickname'] ?? '');
    $avatar = trim($_POST['avatar'] ?? '');
    $gender = $_POST['gender'] ?? 'unknown';
    $role = $_POST['role'] ?? 'user';
    $newPassword = $_POST['new_password'] ?? '';

    // 性别白名单
    $validGenders = ['unknown', 'male', 'female'];

    if ($userId <= 0) {
        $error = '无效的用户ID';
    } elseif (empty($username) || mb_strlen($username) > 20) {
        $error = '用户名不能为空且不能超过20个字符';
    } elseif (mb_strlen($nickname) > 50) {
        $error = '昵称不能超过50个字符';
    } elseif (strlen($avatar) > 255) {
        $error = '头像URL不能超过255个字符';
    } elseif (!in_array($gender, $validGenders)) {
        $error = '无效的性别选项';
    } elseif (!isset($roles[$role])) {
        $error = '无效的角色';
    } elseif (!empty($newPassword) && (strlen($newPassword) < 6 || strlen($newPassword) > 64)) {
        $error = '密码长度必须在6-64位之间';
    } else {
        // 头像去除危险字符防止注入
        $avatar = str_replace(['<', '>', '"', "'"], '', $avatar);
        $canChangeRole = isSuperAdmin();
        $hashedPassword = !empty($newPassword) ? password_hash($newPassword, PASSWORD_DEFAULT) : null;

        if ($canChangeRole && $hashedPassword) {
            $stmt = mysqli_prepare($conn, "UPDATE user_info SET username=?, nickname=?, avatar=?, gender=?, role=?, password=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'ssssssi', $username, $nickname, $avatar, $gender, $role, $hashedPassword, $userId);
        } elseif ($canChangeRole) {
            $stmt = mysqli_prepare($conn, "UPDATE user_info SET username=?, nickname=?, avatar=?, gender=?, role=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'sssssi', $username, $nickname, $avatar, $gender, $role, $userId);
        } elseif ($hashedPassword) {
            $stmt = mysqli_prepare($conn, "UPDATE user_info SET username=?, nickname=?, avatar=?, gender=?, password=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'sssssi', $username, $nickname, $avatar, $gender, $hashedPassword, $userId);
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE user_info SET username=?, nickname=?, avatar=?, gender=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, 'ssssi', $username, $nickname, $avatar, $gender, $userId);
        }

        if (mysqli_stmt_execute($stmt)) {
            SecGuard::writeLog(1, '编辑用户', "ID:{$userId}");
            header('Location: users.php?msg=edit_success');
            exit;
        } else {
            error_log('users.php edit_user failed: ' . mysqli_error($conn));
            $error = '编辑失败，请稍后重试';
        }
    }
}

// 查询用户总数
$total = 0;
if ($search !== '') {
    $likeSearch = "%{$search}%";
    $countStmt = mysqli_prepare($conn, "SELECT COUNT(*) as total FROM user_info WHERE username LIKE ? OR email LIKE ? OR nickname LIKE ?");
    mysqli_stmt_bind_param($countStmt, 'sss', $likeSearch, $likeSearch, $likeSearch);
    mysqli_stmt_execute($countStmt);
    $result = mysqli_stmt_get_result($countStmt);
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $total = (int)$row['total'];
    }
} else {
    $result = mysqli_query($conn, "SELECT COUNT(*) as total FROM user_info");
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $total = (int)$row['total'];
    }
}
$totalPages = ceil($total / $limit);

// 查询用户列表
$users = [];
$sqlError = '';
if ($search !== '') {
    $likeSearch = "%{$search}%";
    $listStmt = mysqli_prepare($conn, "SELECT id, username, nickname, email, avatar, gender, role FROM user_info WHERE username LIKE ? OR email LIKE ? OR nickname LIKE ? ORDER BY id DESC LIMIT ?, ?");
    mysqli_stmt_bind_param($listStmt, 'sssii', $likeSearch, $likeSearch, $likeSearch, $offset, $limit);
    mysqli_stmt_execute($listStmt);
    $result = mysqli_stmt_get_result($listStmt);
} else {
    $listStmt = mysqli_prepare($conn, "SELECT id, username, nickname, email, avatar, gender, role FROM user_info ORDER BY id DESC LIMIT ?, ?");
    mysqli_stmt_bind_param($listStmt, 'ii', $offset, $limit);
    mysqli_stmt_execute($listStmt);
    $result = mysqli_stmt_get_result($listStmt);
}
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row['role_name'] = $roles[$row['role']] ?? '普通用户';
        $row['gender_name'] = $row['gender'] === 'male' ? '男' : ($row['gender'] === 'female' ? '女' : '未知');
        $users[] = $row;
    }
    mysqli_free_result($result);
} else {
    $sqlError = "用户列表查询失败";
    error_log("users.php list query failed: " . mysqli_error($conn));
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="zh-CN" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - 用户管理</title>
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

        /* 消息提示 */
        .message {
            background: rgba(168, 213, 186, 0.15);
            border: 1px solid rgba(168, 213, 186, 0.3);
            color: #7ab893;
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .error {
            background: rgba(232, 176, 176, 0.15);
            border: 1px solid rgba(232, 176, 176, 0.3);
            color: var(--danger);
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* 搜索栏 */
        .search-bar {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            gap: 12px;
            box-shadow: var(--shadow-light);
        }

        .search-bar input {
            flex: 1;
            padding: 12px 16px;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-primary);
            outline: none;
            transition: all 0.3s ease;
        }

        .search-bar input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(201, 169, 212, 0.1);
        }

        .search-bar input::placeholder {
            color: var(--text-muted);
        }

        .search-bar button {
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(201, 169, 212, 0.3);
        }

        .search-bar button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(201, 169, 212, 0.4);
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

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
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

        .avatar-img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--bg-input);
        }

        .action-btn {
            padding: 8px 14px;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .action-btn.delete {
            background: rgba(232, 176, 176, 0.15);
            color: var(--danger);
        }

        .action-btn.delete:hover {
            background: rgba(232, 176, 176, 0.25);
            transform: translateY(-1px);
        }

        .action-btn.edit {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
            margin-right: 8px;
        }

        .action-btn.edit:hover {
            background: rgba(59, 130, 246, 0.25);
            transform: translateY(-1px);
        }

        .action-btn.manage {
            background: rgba(201, 169, 212, 0.15);
            color: var(--primary-dark);
            margin-right: 8px;
        }

        .action-btn.manage:hover {
            background: rgba(201, 169, 212, 0.25);
            transform: translateY(-1px);
        }

        .action-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* 角色标签 */
        .role-tag {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .role-user {
            background: rgba(139, 148, 158, 0.15);
            color: #6b7280;
        }

        .role-moderator {
            background: rgba(234, 179, 8, 0.15);
            color: #d97706;
        }

        .role-admin {
            background: rgba(201, 169, 212, 0.2);
            color: var(--primary-dark);
        }

        /* 分页 */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            padding: 20px;
        }

        .pagination a {
            padding: 10px 14px;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            text-decoration: none;
            color: var(--text-secondary);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .pagination a:hover {
            border-color: var(--primary);
            color: var(--primary-dark);
            transform: translateY(-1px);
        }

        .pagination a.active {
            background: rgba(201, 169, 212, 0.2);
            border-color: var(--primary);
            color: var(--primary-dark);
        }

        .pagination span {
            padding: 10px 14px;
            color: var(--text-muted);
            font-size: 14px;
        }

        .empty {
            padding: 60px;
            text-align: center;
            color: var(--text-muted);
        }

        .empty i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* 弹窗 */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            width: 90%;
            max-width: 400px;
            padding: 24px;
            box-shadow: var(--shadow-hover);
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .modal-close {
            width: 32px;
            height: 32px;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(232, 176, 176, 0.2);
            border-color: var(--danger);
            color: var(--danger);
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .form-select {
            width: 100%;
            padding: 12px 16px;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-primary);
            outline: none;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(201, 169, 212, 0.1);
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .modal-btn {
            padding: 10px 24px;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .modal-btn.cancel {
            background: var(--bg-input);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        .modal-btn.cancel:hover {
            background: rgba(201, 169, 212, 0.1);
            border-color: var(--primary);
            color: var(--text-primary);
        }

        .modal-btn.confirm {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            box-shadow: 0 4px 12px rgba(201, 169, 212, 0.3);
        }

        .modal-btn.confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(201, 169, 212, 0.4);
        }

        /* 响应式 */
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
                <a href="index.php">
                    <i class="fas fa-chart-pie icon"></i>
                    <span>仪表盘</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="users.php" class="active">
                    <i class="fas fa-users icon"></i>
                    <span>用户管理</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="community.php">
                    <i class="fas fa-comments icon"></i>
                    <span>社区管理</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="comments.php">
                    <i class="fas fa-comment-dots icon"></i>
                    <span>留言管理</span>
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
                <h1 class="header-title">用户管理</h1>
                <div class="header-breadcrumb">
                    <a href="index.php">首页</a>
                    <span>/</span>
                    <span>用户管理</span>
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
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'delete_success'): ?>
                <div class="message">
                    <i class="fas fa-check-circle"></i>
                    <span>删除成功</span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'role_success'): ?>
                <div class="message">
                    <i class="fas fa-check-circle"></i>
                    <span>角色修改成功</span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'edit_success'): ?>
                <div class="message">
                    <i class="fas fa-check-circle"></i>
                    <span>编辑成功</span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <!-- 搜索栏 -->
            <div class="search-bar">
                <form method="GET">
                    <input type="text" name="search" placeholder="搜索用户名或邮箱" value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit">
                        <i class="fas fa-search"></i>
                        <span>搜索</span>
                    </button>
                </form>
            </div>

            <!-- 数据表格 -->
            <div class="data-card">
                <div class="card-header">
                    <h3 class="card-title">用户列表</h3>
                    <span style="color: var(--text-muted); font-size: 13px;">共 <?php echo $total; ?> 条记录</span>
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>头像</th>
                            <th>用户名</th>
                            <th>昵称</th>
                            <th>邮箱</th>
                            <th>性别</th>
                            <th>角色</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="8" class="empty">
                                    <i class="fas fa-users"></i>
                                    <p>暂无用户</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td data-label="ID"><?php echo $user['id']; ?></td>
                                    <td data-label="头像"><img src="<?php echo htmlspecialchars($user['avatar']); ?>" class="avatar-img" onerror="this.style.display='none';this.parentElement.innerHTML='<div style=\'width:40px;height:40px;border-radius:50%;background:var(--bg-input);display:flex;align-items:center;justify-content:center;color:var(--text-secondary);font-size:14px;\'>'+this.alt+'</div>';" alt="<?php echo mb_substr($user['username'], 0, 1); ?>"></td>
                                    <td data-label="用户名"><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td data-label="昵称"><?php echo htmlspecialchars($user['nickname']); ?></td>
                                    <td data-label="邮箱"><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td data-label="性别"><?php echo $user['gender_name']; ?></td>
                                    <td data-label="角色">
                                        <span class="role-tag role-<?php echo $user['role']; ?>">
                                            <?php echo $user['role_name']; ?>
                                        </span>
                                    </td>
                                    <td data-label="操作">
                                        <div class="action-buttons">
                                            <button class="action-btn edit" onclick="openEditModal(<?php echo $user['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                                <span>编辑</span>
                                            </button>
                                            <button class="action-btn manage" onclick="openRoleModal(<?php echo $user['id']; ?>, '<?php echo $user['role']; ?>', '<?php echo htmlspecialchars($user['username']); ?>')">
                                                <i class="fas fa-crown"></i>
                                                <span>管理</span>
                                            </button>
                                            <form method="POST" onsubmit="return confirm('确定要删除该用户吗？删除后将无法恢复！');">
                                                <?php echo SecGuard::csrfField(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="action-btn delete">
                                                    <i class="fas fa-trash"></i>
                                                    <span>删除</span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="users.php?search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <?php if ($i == $page): ?>
                                <a href="users.php?search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>" class="active"><?php echo $i; ?></a>
                            <?php else: ?>
                                <a href="users.php?search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="users.php?search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                        
                        <span>共 <?php echo $total; ?> 条</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 修改角色弹窗 -->
    <div class="modal-overlay" id="roleModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">修改用户角色</h3>
                <button class="modal-close" onclick="closeRoleModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">用户名</label>
                    <div style="padding: 12px; background: var(--bg-input); border-radius: 12px; color: var(--text-primary);" id="modalUsername">-</div>
                </div>
                <div class="form-group">
                    <label class="form-label">选择角色</label>
                    <form method="POST" id="roleForm">
                        <?php echo SecGuard::csrfField(); ?>
                        <input type="hidden" name="action" value="update_role">
                        <input type="hidden" name="id" id="modalUserId">
                        <select name="role" class="form-select" id="roleSelect">
                            <option value="user">普通用户</option>
                            <option value="moderator">版主</option>
                            <option value="admin">管理员</option>
                        </select>
                    </form>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" onclick="closeRoleModal()">取消</button>
                <button class="modal-btn confirm" onclick="document.getElementById('roleForm').submit()">确认修改</button>
            </div>
        </div>
    </div>

    <!-- 编辑用户弹窗 -->
    <div class="modal-overlay" id="editModal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h3 class="modal-title">编辑用户信息</h3>
                <button class="modal-close" onclick="closeEditModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editForm">
                    <?php echo SecGuard::csrfField(); ?>
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="id" id="editUserId">
                    
                    <div class="form-group">
                        <label class="form-label">用户名</label>
                        <input type="text" name="username" id="editUsername" class="form-select" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">昵称</label>
                        <input type="text" name="nickname" id="editNickname" class="form-select">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">邮箱</label>
                        <input type="email" name="email" id="editEmail" class="form-select" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">头像 URL</label>
                        <input type="text" name="avatar" id="editAvatar" class="form-select" placeholder="输入头像图片地址">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">性别</label>
                        <select name="gender" class="form-select" id="editGender">
                            <option value="unknown">未知</option>
                            <option value="male">男</option>
                            <option value="female">女</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">角色</label>
                        <select name="role" class="form-select" id="editRole">
                            <option value="user">普通用户</option>
                            <option value="moderator">版主</option>
                            <option value="admin">管理员</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">重置密码</label>
                        <input type="password" name="new_password" id="editPassword" class="form-select" placeholder="不填写则不修改密码">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" onclick="closeEditModal()">取消</button>
                <button class="modal-btn confirm" onclick="document.getElementById('editForm').submit()">保存修改</button>
            </div>
        </div>
    </div>

    <script>
        // 用户数据数组，用于填充编辑弹窗
        var userData = <?php echo json_encode($users); ?>;

        function openRoleModal(userId, currentRole, username) {
            document.getElementById('modalUserId').value = userId;
            document.getElementById('modalUsername').textContent = username;
            document.getElementById('roleSelect').value = currentRole;
            document.getElementById('roleModal').classList.add('active');
        }

        function closeRoleModal() {
            document.getElementById('roleModal').classList.remove('active');
        }

        // 点击遮罩层关闭角色弹窗
        document.getElementById('roleModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRoleModal();
            }
        });

        // 编辑用户弹窗
        function openEditModal(userId) {
            var user = userData.find(function(u) { return u.id === userId; });
            if (user) {
                document.getElementById('editUserId').value = user.id;
                document.getElementById('editUsername').value = user.username;
                document.getElementById('editNickname').value = user.nickname || '';
                document.getElementById('editEmail').value = user.email;
                document.getElementById('editAvatar').value = user.avatar || '';
                document.getElementById('editGender').value = user.gender || 'unknown';
                document.getElementById('editRole').value = user.role || 'user';
                document.getElementById('editPassword').value = '';
            }
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        // 点击遮罩层关闭编辑弹窗
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });

        // ESC键关闭所有弹窗
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeRoleModal();
                closeEditModal();
            }
        });

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
