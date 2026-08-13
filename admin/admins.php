<?php
require_once 'config.php';
checkAdminLogin();

// 只有超级管理员可以访问
if (!isSuperAdmin()) {
    header('Location: index.php');
    exit;
}

$admin = getCurrentAdmin();
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// 角色定义
$roles = [
    'super_admin' => '超级管理员',
    'admin' => '管理员'
];

// 删除管理员（不能删除自己，普通管理员不能删除超级管理员）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    SecGuard::verifyCsrf(true);
    $adminId = intval($_POST['id']);
    if ($adminId <= 0) {
        $error = '无效的管理员ID';
    } elseif ($adminId === $admin['id']) {
        $error = '不能删除自己';
    } else {
        $checkStmt = mysqli_prepare($conn, "SELECT role FROM admin_users WHERE id = ?");
        mysqli_stmt_bind_param($checkStmt, 'i', $adminId);
        mysqli_stmt_execute($checkStmt);
        $targetAdmin = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));
        if (!$targetAdmin) {
            $error = '管理员不存在';
        } elseif ($targetAdmin['role'] === 'super_admin' && !isSuperAdmin()) {
            $error = '无权删除超级管理员';
        } else {
            $stmt = mysqli_prepare($conn, "DELETE FROM admin_users WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $adminId);
            if (mysqli_stmt_execute($stmt)) {
                SecGuard::writeLog(1, '删除管理员', "ID:{$adminId}");
                header('Location: admins.php?msg=delete_success');
                exit;
            } else {
                error_log('admins.php delete failed: ' . mysqli_error($conn));
                $error = '删除失败，请稍后重试';
            }
        }
    }
}

// 修改管理员角色
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_role') {
    SecGuard::verifyCsrf(true);
    $adminId = intval($_POST['id']);
    $role = $_POST['role'] ?? 'admin';
    if ($adminId <= 0) {
        $error = '无效的管理员ID';
    } elseif (!isset($roles[$role])) {
        $error = '无效的角色';
    } elseif ($adminId === $admin['id']) {
        $error = '不能修改自己的角色';
    } else {
        $checkStmt = mysqli_prepare($conn, "SELECT role FROM admin_users WHERE id = ?");
        mysqli_stmt_bind_param($checkStmt, 'i', $adminId);
        mysqli_stmt_execute($checkStmt);
        $targetAdmin = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));
        if (!$targetAdmin) {
            $error = '管理员不存在';
        } elseif ($targetAdmin['role'] === 'super_admin' && !isSuperAdmin()) {
            $error = '无权修改超级管理员';
        } else {
            $stmt = mysqli_prepare($conn, "UPDATE admin_users SET role = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'si', $role, $adminId);
            if (mysqli_stmt_execute($stmt)) {
                SecGuard::writeLog(1, '修改管理员角色', "ID:{$adminId} Role:{$role}");
                header('Location: admins.php?msg=role_success');
                exit;
            } else {
                error_log('admins.php update_role failed: ' . mysqli_error($conn));
                $error = '修改角色失败，请稍后重试';
            }
        }
    }
}

// 修改管理员信息
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_admin') {
    SecGuard::verifyCsrf(true);
    $adminId = intval($_POST['id']);
    $username = trim($_POST['username'] ?? '');
    $nickname = trim($_POST['nickname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'admin';
    $newPassword = $_POST['new_password'] ?? '';

    if ($adminId <= 0) {
        $error = '无效的管理员ID';
    } elseif (empty($username) || mb_strlen($username) > 20) {
        $error = '用户名不能为空且不能超过20个字符';
    } elseif (mb_strlen($nickname) > 50) {
        $error = '昵称不能超过50个字符';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式不正确';
    } elseif (mb_strlen($email) > 255) {
        $error = '邮箱长度不能超过255个字符';
    } elseif (!empty($newPassword) && (strlen($newPassword) < 6 || strlen($newPassword) > 64)) {
        $error = '密码长度必须在6-64位之间';
    } elseif (!isset($roles[$role])) {
        $error = '无效的角色';
    } else {
        // 检查目标管理员是否存在及角色
        $checkStmt = mysqli_prepare($conn, "SELECT role FROM admin_users WHERE id = ?");
        mysqli_stmt_bind_param($checkStmt, 'i', $adminId);
        mysqli_stmt_execute($checkStmt);
        $targetAdmin = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));
        if (!$targetAdmin) {
            $error = '管理员不存在';
        } elseif ($targetAdmin['role'] === 'super_admin' && !isSuperAdmin()) {
            $error = '无权编辑超级管理员';
        } else {
            $canChangeRole = ($adminId !== $admin['id']) && isSuperAdmin();
            $hashedPassword = !empty($newPassword) ? password_hash($newPassword, PASSWORD_DEFAULT) : null;

            if ($canChangeRole && $hashedPassword) {
                $stmt = mysqli_prepare($conn, "UPDATE admin_users SET username=?, nickname=?, email=?, role=?, password=? WHERE id=?");
                mysqli_stmt_bind_param($stmt, 'sssssi', $username, $nickname, $email, $role, $hashedPassword, $adminId);
            } elseif ($canChangeRole) {
                $stmt = mysqli_prepare($conn, "UPDATE admin_users SET username=?, nickname=?, email=?, role=? WHERE id=?");
                mysqli_stmt_bind_param($stmt, 'ssssi', $username, $nickname, $email, $role, $adminId);
            } elseif ($hashedPassword) {
                $stmt = mysqli_prepare($conn, "UPDATE admin_users SET username=?, nickname=?, email=?, password=? WHERE id=?");
                mysqli_stmt_bind_param($stmt, 'ssssi', $username, $nickname, $email, $hashedPassword, $adminId);
            } else {
                $stmt = mysqli_prepare($conn, "UPDATE admin_users SET username=?, nickname=?, email=? WHERE id=?");
                mysqli_stmt_bind_param($stmt, 'sssi', $username, $nickname, $email, $adminId);
            }

            if (mysqli_stmt_execute($stmt)) {
                SecGuard::writeLog(1, '编辑管理员', "ID:{$adminId}");
                header('Location: admins.php?msg=edit_success');
                exit;
            } else {
                error_log('admins.php edit_admin failed: ' . mysqli_error($conn));
                $error = '编辑失败，请稍后重试';
            }
        }
    }
}

// 添加管理员
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_admin') {
    SecGuard::verifyCsrf(true);
    $username = trim($_POST['username'] ?? '');
    $nickname = trim($_POST['nickname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'admin';

    if (empty($username) || mb_strlen($username) > 20) {
        $error = '用户名不能为空且不能超过20个字符';
    } elseif (mb_strlen($nickname) > 50) {
        $error = '昵称不能超过50个字符';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式不正确';
    } elseif (mb_strlen($email) > 255) {
        $error = '邮箱长度不能超过255个字符';
    } elseif (empty($password) || strlen($password) < 6 || strlen($password) > 64) {
        $error = '密码不能为空，长度必须在6-64位之间';
    } elseif (!isset($roles[$role])) {
        $error = '无效的角色';
    } else {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "INSERT INTO admin_users (username, password, email, nickname, role) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'sssss', $username, $hashedPassword, $email, $nickname, $role);
        if (mysqli_stmt_execute($stmt)) {
            SecGuard::writeLog(1, '添加管理员', "User:{$username} Role:{$role}");
            header('Location: admins.php?msg=add_success');
            exit;
        } else {
            error_log('admins.php add_admin failed: ' . mysqli_error($conn));
            $error = '添加失败，请稍后重试';
        }
    }
}

// 查询管理员总数
$total = 0;
if ($search !== '') {
    $likeSearch = "%{$search}%";
    $countStmt = mysqli_prepare($conn, "SELECT COUNT(*) as total FROM admin_users WHERE username LIKE ? OR nickname LIKE ? OR email LIKE ?");
    mysqli_stmt_bind_param($countStmt, 'sss', $likeSearch, $likeSearch, $likeSearch);
    mysqli_stmt_execute($countStmt);
    $result = mysqli_stmt_get_result($countStmt);
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $total = (int)$row['total'];
    }
} else {
    $result = mysqli_query($conn, "SELECT COUNT(*) as total FROM admin_users");
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $total = (int)$row['total'];
    }
}
$totalPages = ceil($total / $limit);

// 查询管理员列表
$admins = [];
if ($search !== '') {
    $likeSearch = "%{$search}%";
    $listStmt = mysqli_prepare($conn, "SELECT id, username, nickname, email, role, status, create_time FROM admin_users WHERE username LIKE ? OR nickname LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT ?, ?");
    mysqli_stmt_bind_param($listStmt, 'sssii', $likeSearch, $likeSearch, $likeSearch, $offset, $limit);
    mysqli_stmt_execute($listStmt);
    $result = mysqli_stmt_get_result($listStmt);
} else {
    $listStmt = mysqli_prepare($conn, "SELECT id, username, nickname, email, role, status, create_time FROM admin_users ORDER BY id DESC LIMIT ?, ?");
    mysqli_stmt_bind_param($listStmt, 'ii', $offset, $limit);
    mysqli_stmt_execute($listStmt);
    $result = mysqli_stmt_get_result($listStmt);
}
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row['role_name'] = $roles[$row['role']] ?? '管理员';
        $row['status_name'] = $row['status'] == 1 ? '启用' : '禁用';
        $admins[] = $row;
    }
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="zh-CN" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员管理</title>
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
            flex-wrap: wrap;
        }

        .search-bar input {
            flex: 1;
            min-width: 200px;
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

        .add-btn {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(201, 169, 212, 0.3);
        }

        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(201, 169, 212, 0.4);
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

        .role-tag {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .role-super_admin {
            background: rgba(232, 176, 176, 0.15);
            color: var(--danger);
        }

        .role-admin {
            background: rgba(201, 169, 212, 0.2);
            color: var(--primary-dark);
        }

        .status-tag {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-active {
            background: rgba(168, 213, 186, 0.15);
            color: #7ab893;
        }

        .status-disabled {
            background: rgba(139, 148, 158, 0.15);
            color: #6b7280;
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
            margin-right: 8px;
        }

        .action-btn.edit {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .action-btn.edit:hover {
            background: rgba(59, 130, 246, 0.25);
            transform: translateY(-1px);
        }

        .action-btn.role {
            background: rgba(234, 179, 8, 0.15);
            color: #d97706;
        }

        .action-btn.role:hover {
            background: rgba(234, 179, 8, 0.25);
            transform: translateY(-1px);
        }

        .action-btn.delete {
            background: rgba(232, 197, 208, 0.15);
            color: #d6486b;
        }

        .action-btn.delete:hover {
            background: rgba(232, 197, 208, 0.25);
            transform: translateY(-1px);
        }

        .action-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
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
            max-width: 600px;
            overflow: hidden;
            box-shadow: var(--shadow-hover);
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            transition: color 0.2s;
        }

        .modal-close:hover {
            color: var(--text-primary);
        }

        .modal-body {
            padding: 24px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
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
        }

        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(201, 169, 212, 0.1);
        }

        .form-select::placeholder {
            color: var(--text-muted);
        }

        .modal-footer {
            padding: 16px 24px;
            background: var(--bg-input);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .modal-btn {
            padding: 10px 24px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
        }

        .modal-btn.cancel {
            background: var(--bg-card);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }

        .modal-btn.cancel:hover {
            background: rgba(201, 169, 212, 0.1);
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

            .action-buttons {
                flex-direction: column;
                align-items: flex-start;
            }

            .action-btn {
                margin-right: 0;
                margin-bottom: 4px;
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
                <a href="users.php">
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
            <div class="menu-item">
                <a href="admins.php" class="active">
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
                <h1 class="header-title">管理员管理</h1>
                <div class="header-breadcrumb">
                    <a href="index.php">首页</a>
                    <span>/</span>
                    <span>管理员管理</span>
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
            
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'add_success'): ?>
                <div class="message">
                    <i class="fas fa-check-circle"></i>
                    <span>添加成功</span>
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
                    <input type="text" name="search" placeholder="搜索用户名、昵称或邮箱" value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit">
                        <i class="fas fa-search"></i>
                        <span>搜索</span>
                    </button>
                    <button type="button" class="add-btn" onclick="openAddModal()">
                        <i class="fas fa-plus"></i>
                        <span>添加管理员</span>
                    </button>
                </form>
            </div>

            <!-- 数据表格 -->
            <div class="data-card">
                <div class="card-header">
                    <h3 class="card-title">管理员列表</h3>
                    <span style="color: var(--text-muted); font-size: 13px;">共 <?php echo $total; ?> 条记录</span>
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>用户名</th>
                            <th>昵称</th>
                            <th>邮箱</th>
                            <th>角色</th>
                            <th>状态</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($admins)): ?>
                            <tr>
                                <td colspan="8" class="empty">
                                    <i class="fas fa-users"></i>
                                    <p>暂无管理员</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($admins as $admin_item): ?>
                                <tr>
                                    <td data-label="ID"><?php echo $admin_item['id']; ?></td>
                                    <td data-label="用户名"><?php echo htmlspecialchars($admin_item['username']); ?></td>
                                    <td data-label="昵称"><?php echo htmlspecialchars($admin_item['nickname']); ?></td>
                                    <td data-label="邮箱"><?php echo htmlspecialchars($admin_item['email']); ?></td>
                                    <td data-label="角色">
                                        <span class="role-tag role-<?php echo $admin_item['role']; ?>">
                                            <?php echo $admin_item['role_name']; ?>
                                        </span>
                                    </td>
                                    <td data-label="状态">
                                        <span class="status-tag status-<?php echo $admin_item['status'] == 1 ? 'active' : 'disabled'; ?>">
                                            <?php echo $admin_item['status_name']; ?>
                                        </span>
                                    </td>
                                    <td data-label="创建时间"><?php echo date('Y-m-d H:i:s', strtotime($admin_item['create_time'])); ?></td>
                                    <td data-label="操作">
                                        <div class="action-buttons">
                                            <button class="action-btn edit" onclick="openEditModal(<?php echo $admin_item['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                                <span>编辑</span>
                                            </button>
                                            <?php if ($admin_item['id'] !== $admin['id']): ?>
                                                <button class="action-btn role" onclick="openRoleModal(<?php echo $admin_item['id']; ?>, '<?php echo $admin_item['role']; ?>', '<?php echo htmlspecialchars($admin_item['username']); ?>')">
                                                    <i class="fas fa-crown"></i>
                                                    <span>设置角色</span>
                                                </button>
                                                <form method="POST" onsubmit="return confirm('确定要删除该管理员吗？删除后将无法恢复！');">
                                                    <?php echo SecGuard::csrfField(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo $admin_item['id']; ?>">
                                                    <button type="submit" class="action-btn delete">
                                                        <i class="fas fa-trash"></i>
                                                        <span>删除</span>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted); font-size: 13px;">当前用户</span>
                                            <?php endif; ?>
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
                            <a href="admins.php?search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <?php if ($i == $page): ?>
                                <a href="admins.php?search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>" class="active"><?php echo $i; ?></a>
                            <?php else: ?>
                                <a href="admins.php?search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="admins.php?search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">
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
                <h3 class="modal-title">修改管理员角色</h3>
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
                        <input type="hidden" name="id" id="modalAdminId">
                        <select name="role" class="form-select" id="roleSelect">
                            <option value="admin">管理员</option>
                            <option value="super_admin">超级管理员</option>
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

    <!-- 添加管理员弹窗 -->
    <div class="modal-overlay" id="addModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">添加管理员</h3>
                <button class="modal-close" onclick="closeAddModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addForm">
                    <?php echo SecGuard::csrfField(); ?>
                    <input type="hidden" name="action" value="add_admin">
                    
                    <div class="form-group">
                        <label class="form-label">用户名</label>
                        <input type="text" name="username" class="form-select" required placeholder="请输入用户名">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">昵称</label>
                        <input type="text" name="nickname" class="form-select" placeholder="请输入昵称">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">邮箱</label>
                        <input type="email" name="email" class="form-select" placeholder="请输入邮箱">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">密码</label>
                        <input type="password" name="password" class="form-select" required placeholder="请输入密码">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">角色</label>
                        <select name="role" class="form-select">
                            <option value="admin">管理员</option>
                            <option value="super_admin">超级管理员</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="modal-btn cancel" onclick="closeAddModal()">取消</button>
                <button class="modal-btn confirm" onclick="document.getElementById('addForm').submit()">添加</button>
            </div>
        </div>
    </div>

    <!-- 编辑管理员弹窗 -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">编辑管理员信息</h3>
                <button class="modal-close" onclick="closeEditModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editForm">
                    <?php echo SecGuard::csrfField(); ?>
                    <input type="hidden" name="action" value="edit_admin">
                    <input type="hidden" name="id" id="editAdminId">
                    
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
                        <input type="email" name="email" id="editEmail" class="form-select">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">角色</label>
                        <select name="role" class="form-select" id="editRole">
                            <option value="admin">管理员</option>
                            <option value="super_admin">超级管理员</option>
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
        // 管理员数据数组
        var adminData = <?php echo json_encode($admins); ?>;

        // 角色弹窗
        function openRoleModal(adminId, currentRole, username) {
            document.getElementById('modalAdminId').value = adminId;
            document.getElementById('modalUsername').textContent = username;
            document.getElementById('roleSelect').value = currentRole;
            document.getElementById('roleModal').classList.add('active');
        }

        function closeRoleModal() {
            document.getElementById('roleModal').classList.remove('active');
        }

        document.getElementById('roleModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRoleModal();
            }
        });

        // 添加管理员弹窗
        function openAddModal() {
            document.getElementById('addForm').reset();
            document.getElementById('addModal').classList.add('active');
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.remove('active');
        }

        document.getElementById('addModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddModal();
            }
        });

        // 编辑管理员弹窗
        function openEditModal(adminId) {
            var admin = adminData.find(function(a) { return a.id === adminId; });
            if (admin) {
                document.getElementById('editAdminId').value = admin.id;
                document.getElementById('editUsername').value = admin.username;
                document.getElementById('editNickname').value = admin.nickname || '';
                document.getElementById('editEmail').value = admin.email || '';
                document.getElementById('editRole').value = admin.role || 'admin';
                document.getElementById('editPassword').value = '';
            }
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
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