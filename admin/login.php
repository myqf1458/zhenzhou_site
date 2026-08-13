<?php
session_start();
require_once '../db.php';

// 加载安全防护
require_once __DIR__ . '/../admin_security.php';
SecGuard::init($conn);

$error = '';
$lockInfo = SecGuard::isLoginLocked();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 登录页 CSRF 独立校验
    $csrf = $_POST['_sec_csrf'] ?? '';
    if (empty($_SESSION['_sec_csrf']) || empty($csrf) || !hash_equals($_SESSION['_sec_csrf'], $csrf)) {
        $error = '安全校验失败(CSRF)，请刷新页面重试';
    } elseif (!empty($lockInfo['locked'])) {
        $error = '登录失败次数过多，已临时封禁。请于 ' . $lockInfo['unlock'] . ' 后重试。';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($username) || empty($password)) {
            $error = '用户名和密码不能为空';
        } else {
            // 登录耗时添加随机延迟（抵御时序攻击）
            usleep(rand(50000, 200000));

            $sql = "SELECT id, username, password, nickname, role, status FROM admin_users WHERE username = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if ($admin = mysqli_fetch_assoc($result)) {
                if ($admin['status'] != 1) {
                    $error = '账号已被禁用';
                    SecGuard::recordLoginFail($username);
                } elseif (password_verify($password, $admin['password'])) {
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_username'] = $admin['username'];
                    $_SESSION['admin_nickname'] = $admin['nickname'];
                    $_SESSION['admin_role'] = $admin['role'] ?? 'admin';
                    SecGuard::onLoginSuccess($admin['username']);
                    header('Location: index.php');
                    exit;
                } else {
                    $error = '密码错误';
                    SecGuard::recordLoginFail($username);
                }
            } else {
                $error = '用户名不存在';
                SecGuard::recordLoginFail($username);
            }
        }
    }
    // 刷新封禁状态
    $lockInfo = SecGuard::isLoginLocked();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="img/favicon.png" type="image/jpeg">
    <link rel="shortcut icon" href="img/favicon.png" type="image/jpeg">
    <!--=============== remixicon.com 图标库 ===============-->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.2.0/remixicon.css">
    <!--=============== CSS ===============-->
    <link rel="stylesheet" href="assets/css/styles.css">
    <title>后台管理系统</title>
    <style>
        /* =============== styles.css 未定义的项目补充样式 =============== */

        /* 提交按钮 disabled 状态（防重复提交） */
        .login__button:disabled,
        .login__button[disabled] {
            opacity: 0.75;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
            background-color: var(--first-color);
        }

        /* 错误 & 封禁提示 */
        .login__alert {
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13px;
            line-height: 1.55;
        }
        .login__alert i {
            font-size: 16px;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .login__alert--error {
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.22);
            color: #c0392b;
        }
        .login__alert--error i { color: #ef4444; }
        .login__alert--warn {
            background: rgba(243, 156, 18, 0.08);
            border: 1px solid rgba(243, 156, 18, 0.22);
            color: #b07810;
        }
        .login__alert--warn i { color: #f39c12; }

        /* 提示文本 & 页脚/备案 */
        .login__tip {
            text-align: center;
            margin-top: -0.5rem;
            margin-bottom: 1rem;
            font-size: var(--small-font-size);
            color: var(--text-color);
        }
        .login-footer {
            text-align: center;
            margin-top: 2rem;
            font-size: var(--small-font-size);
            color: var(--text-color);
        }
        .beian-inner {
            text-align: center;
            margin-top: 0.8rem;
            font-size: var(--tiny-font-size);
        }
        .beian-inner a {
            color: var(--text-color);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: color 0.2s;
        }
        .beian-inner img {
            height: 16px;
            width: auto;
            border-radius: 2px;
        }
        .beian-inner a:hover { color: var(--first-color); }
        .icp-beian {
            text-align: center;
            margin-top: 0.6rem;
            font-size: var(--tiny-font-size);
        }
        .icp-beian a {
            color: var(--text-color);
            text-decoration: none;
            transition: color 0.2s;
        }
        .icp-beian a:hover { color: var(--first-color); }
    </style>
</head>
<body>
    <!--=============== 登录图片（SVG blob 遮罩 + 背景图） ===============-->
    <svg class="login__blob" viewBox="0 0 566 840" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <mask id="mask0" mask-type="alpha">
            <path d="M342.407 73.6315C388.53 56.4007 394.378 17.3643 391.538
            0H566V840H0C14.5385 834.991 100.266 804.436 77.2046 707.263C49.6393
            591.11 115.306 518.927 176.468 488.873C363.385 397.026 156.98 302.824
            167.945 179.32C173.46 117.209 284.755 95.1699 342.407 73.6315Z"></path>
        </mask>

        <g mask="url(#mask0)">
            <path d="M342.407 73.6315C388.53 56.4007 394.378 17.3643 391.538
            0H566V840H0C14.5385 834.991 100.266 804.436 77.2046 707.263C49.6393
            591.11 115.306 518.927 176.468 488.873C363.385 397.026 156.98 302.824
            167.945 179.32C173.46 117.209 284.755 95.1699 342.407 73.6315Z"></path>

            <image class="login__img" href="assets/img/bg-img.jpg"></image>
        </g>
    </svg>

    <div class="login container grid" id="loginAccessRegister">
        <div class="login__access">
            <h1 class="login__title">后 台 登 入</h1>

            <div class="login__area">
                <?php if (!empty($lockInfo['locked']) && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
                    <div class="login__alert login__alert--warn">
                        <i class="ri-shield-keyhole-line"></i>
                        <div>登录已被临时限制，将于 <?php echo htmlspecialchars($lockInfo['unlock']); ?> 后解除</div>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="login__alert login__alert--error">
                        <i class="ri-error-warning-line"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" class="login__form" autocomplete="on">
                    <?php echo SecGuard::csrfField(); ?>

                    <div class="login__content login__group grid">
                        <!-- 用户名输入框 -->
                        <div class="login__box">
                            <input type="text" id="username" name="username" required placeholder=" "
                                   class="login__input"
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                                   autocomplete="username">
                            <label for="username" class="login__label">用户名</label>

                            <i class="ri-user-fill login__icon"></i>
                        </div>

                        <div class="login__box">
                            <input type="password" id="password" name="password" required placeholder=" "
                                   class="login__input"
                                   autocomplete="current-password">
                            <label for="password" class="login__label">密码</label>

                            <i class="ri-eye-off-fill login__icon login__password" id="loginPassword"></i>
                        </div>
                    </div>

                    <button type="submit" class="login__button">登 录</button>
                </form>

                <p class="login__tip">
                    如忘记密码，请联系超级管理员重置
                </p>
            </div>

            <div class="login-footer">
                © 真昼小站 · 后台管理系统 · 建湖梦玖信息技术工作室（个体工商户）
            </div>

            <div class="icp-beian">
                <!-- 如已为站点完成 ICP 备案，请在此处替换为你的备案号 -->
                <a href="https://beian.miit.gov.cn/" target="_blank" rel="noreferrer">ICP备案号</a>
            </div>

            <div class="beian-inner">
                <!-- 如已完成公安备案，请在此处替换为你的公安备案号和查询链接 -->
                <a href="https://beian.mps.gov.cn/" rel="noreferrer" target="_blank">
                    <img src="img/beian-icon.png" alt="公安备案图标">
                    公网安备号
                </a>
            </div>
        </div>


    <script src="assets/js/main.js"></script>
    <script>
        // 密码显示切换已由 main.js::passwordAccess 绑定，此处仅做保护：
        // 防止 main.js 在 DOM 前加载时失效（兼容加载顺序问题）
        (function () {
            var toggle = document.getElementById('loginPassword');
            var input  = document.getElementById('password');
            if (!toggle || !input) return;
            if (toggle.dataset.bound === '1') return;
            toggle.dataset.bound = '1';
        })();

        // 防止重复提交
        (function () {
            var form = document.querySelector('.login__form');
            if (!form) return;
            form.addEventListener('submit', function () {
                var btn = form.querySelector('.login__button');
                if (btn && !btn.disabled) {
                    btn.disabled = true;
                    btn.innerText = '登 录 中…';
                }
            });
        })();

        // 后台登录页不需要"切换到注册"：若用户意外点到注册占位，立即切回登录
        (function () {
            var root = document.getElementById('loginAccessRegister');
            if (root) {
                root.classList.remove('active');
                // 同时禁用注册占位表单的提交
                var regForm = root.querySelector('.login__register');
                if (regForm) {
                    regForm.addEventListener('submit', function (e) { e.preventDefault(); });
                }
            }
        })();
    </script>
</body>
</html>