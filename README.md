# 真昼小站

一个以《邻家天使》女主「椎名真昼」为主题的爱好者社区站点，基于原生 PHP + MySQL 构建，集成用户系统、社区板块、追番列表、友链管理、邮件验证码、QQ 互联登录等功能。

> 项目仅作技术与二次元交流用途，开源供学习参考。
版权所有 © 2026 建湖梦玖信息技术工作室（个体工商户）

## 功能特性

- **用户系统**：注册 / 登录 / 找回密码 / 邮箱换绑 / QQ 互联登录 / 头像框
- **社区板块**：多板块分区发帖、表情、评论、点赞
- **追番列表**：通过 B 站 UID 同步追番数据展示
- **友链系统**：前台申请 + 后台审核 + 对方站点反向链接检测
- **留言板 / 赞赏**：访客留言与赞助入口
- **签到积分**：每日签到、商店兑换特权
- **后台管理**：管理员、用户、评论、友链、举报、安全策略、QQ 配置、七牛云
- **安全防护**：CSRF 防护、登录锁定、防盗链、敏感配置文件保护、安全响应头

## 技术栈

- **后端**：原生 PHP 7.4+（无框架）/ MySQL 5.7+
- **前端**：原生 HTML / CSS / JavaScript（含 iconfont 图标）
- **集成**：QQ 互联 OAuth、B 站 API、SMTP 邮件、七牛云对象存储（可选）

## 环境要求

- PHP ≥ 7.4（推荐 8.0+），需启用 `mysqli`、`mbstring`、`curl` 扩展
- MySQL ≥ 5.7（推荐 utf8mb4 编码）
- Apache（需支持 `.htaccess` 重写）或 Nginx（需自行转换重写规则）

## 快速部署

### 1. 获取源码

```bash
git clone https://github.com/your-username/shiinamahiru-site.git
cd shiinamahiru-site
```

### 2. 配置 Web 服务器

将网站根目录指向本项目目录，并确保 PHP 可解析。Apache 用户可直接使用项目自带的 `.htaccess`。

### 3. 准备配置文件（三选一）

**方式 A：复制模板手动填写（推荐）**

```bash
cp config.example.php config.local.php
```

编辑 `config.local.php`，填入数据库、SMTP、站点信息。

**方式 B：通过安装向导生成**

浏览器访问 `https://your-domain.com/admin/install.php`，按提示填写数据库与管理员账号，向导会自动生成 `config.local.php`（不含 SMTP 与站点 URL，需后续手动补充）。

**方式 C：环境变量注入（适合容器化部署）**

通过环境变量覆盖配置，键名形如：

| 配置项        | 环境变量      |
| ------------- | ------------- |
| `db.host`     | `DB_HOST`     |
| `db.user`     | `DB_USER`     |
| `db.pass`     | `DB_PASS`     |
| `db.name`     | `DB_NAME`     |
| `smtp.host`   | `SMTP_HOST`   |
| `smtp.port`   | `SMTP_PORT`   |
| `smtp.user`   | `SMTP_USER`   |
| `smtp.pass`   | `SMTP_PASS`   |
| `site.url`    | `SITE_URL`    |
| `site.name`   | `SITE_NAME`   |

优先级：**环境变量 > config.local.php > 默认值**。

### 4. 配置 QQ 互联登录（可选）

- 在 [QQ 互联开放平台](https://connect.qq.com/) 注册应用，获取 `app_id` / `app_key`
- 编辑 `qq_oauth_config.php`（默认为空占位配置），填入凭证并将 `enabled` 改为 `1`
- 或登录后台 → 「QQ登录配置」页面填写

> **重要**：在 `qq_oauth_config.php` 中填入真实凭证后，请执行 `git rm --cached qq_oauth_config.php` 将其从版本控制中移除，避免凭证泄露。

### 5. 配置 SMTP 邮件

邮件验证码使用 SMTP 发送，推荐使用 QQ 邮箱授权码（非登录密码）。在 `config.local.php` 的 `smtp` 段填入：

```php
'smtp' => [
    'host'      => 'ssl://smtp.qq.com',
    'port'      => 465,
    'user'      => 'your_email@qq.com',
    'pass'      => 'your_smtp_auth_code',  // SMTP 授权码
    'from_name' => '真昼小站',
],
```

### 6. 访问站点

访问 `https://your-domain.com/` 进入首页，访问 `https://your-domain.com/admin/login.php` 进入后台。

## 目录结构

```
.
├── admin/                  # 后台管理（管理员、用户、评论、友链、举报等）
│   └── install.php         # 安装向导
├── sql/                    # 数据库脚本
│   ├── user.sql            # 用户表
│   ├── bangumi.sql         # 追番表
│   └── community.sql       # 社区表
├── img/                    # 图片资源
├── icon/                   # SVG 图标
├── font_*/                 # iconfont 字体
├── config.php              # 集中式配置加载器（读取本地配置/环境变量）
├── config.example.php      # 配置模板（复制为 config.local.php 使用）
├── config.local.php        # 本地配置（不提交，需自行创建）
├── qq_oauth_config.php     # QQ 互联配置（默认空模板）
├── db.php / db_config.php  # 数据库连接
├── .htaccess               # Apache 安全与重写规则
├── index.html              # 首页
├── Login.html              # 登录注册页
├── community.html          # 社区页
├── bangumi.html            # 追番页
└── ...                     # 其他功能页与接口
```

## 安全提示

- **敏感配置文件已通过 `.htaccess` 禁止外部直接访问**：`config.local.php`、`db.php`、`qq_oauth_config.php` 等
- **`config.local.php` 已加入 `.gitignore`**：包含数据库凭证与 SMTP 授权码，请勿手动提交
- **安装向导生成 `admin/install.lock`**：标记系统已安装，已加入 `.gitignore`，生产环境请保留该文件
- **填写真实 QQ 凭证后**：请执行 `git rm --cached qq_oauth_config.php` 从版本控制移除
- **生产环境部署后**：建议删除或重命名 `admin/install.php`，避免被未授权访问

## 开源协议

本项目基于 [MIT License](./LICENSE) 开源，可自由使用、修改和分发，请保留原始版权声明。

## 致谢

- 站点主题灵感来源：《邻家天使》女主 椎名真昼
- 项目名 `shinamah` 由女主罗马音 `Shiina Mahiru` 缩写融合而来
