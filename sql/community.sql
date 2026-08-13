-- =============================================
-- 真昼小站 社区模块数据库脚本（v2 - 含板块分区）
-- 字符集: utf8mb4
-- 请先将下方表创建到你的目标数据库中（如已通过安装向导建库可跳过）
-- =============================================

-- USE `your_db_name`;

-- -------------------------------------------
-- 社区板块表 community_boards
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `community_boards` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '板块ID',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '板块名称',
  `icon` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '板块图标/emoji',
  `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '板块描述',
  `sort` INT(11) NOT NULL DEFAULT 0 COMMENT '排序（小→大）',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态：1-正常 0-已禁用',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_sort` (`sort`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='社区板块表';

-- -------------------------------------------
-- 默认板块数据
-- -------------------------------------------
INSERT IGNORE INTO `community_boards` (`id`, `name`, `icon`, `description`, `sort`) VALUES
(1, '全部动态', '', '所有板块的帖子', 0),
(2, '动漫讨论', '', '动画、轻小说相关讨论', 10),
(3, '生活日常', '', '日常碎碎念、生活分享', 20),
(4, '作品分享', '', '绘画、剪辑、手作等创作', 30),
(5, '互动交流', '', '提问、求助、闲聊', 40),
(6, '资源共享', '', '壁纸、音乐、电子书等', 50),
(7, '活动专区', '', '站内活动和公告', 60);

-- -------------------------------------------
-- 社区帖子表 community_posts（v2 - 含 board_id / title）
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `community_posts` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '帖子ID',
  `board_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属板块ID（关联community_boards.id）',
  `user_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '发帖用户ID（关联user_info.id）',
  `author` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '发帖用户名（冗余）',
  `avatar` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '用户头像URL',
  `title` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '帖子标题（可选）',
  `content` TEXT NOT NULL COMMENT '发表内容',
  `emoji` VARCHAR(10) DEFAULT NULL COMMENT '选择的表情符号',
  `qq` VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'QQ号',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态：1-正常 0-已删除 2-已屏蔽',
  `user_ip` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '发帖者IP',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '发帖时间',
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_board_time` (`board_id`, `create_time`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='社区帖子表';

-- -------------------------------------------
-- 示例数据（注意 board_id 对应上面板块id，2=动漫讨论 3=生活日常 等）
-- -------------------------------------------
INSERT IGNORE INTO `community_posts` (`board_id`, `user_id`, `author`, `avatar`, `title`, `content`, `emoji`, `qq`, `status`, `create_time`) VALUES
(7, 0, '小真昼', 'img/5.jpg', '欢迎来到真昼社区！', '欢迎来到真昼社区！这里是大家交流分享的地方，可以发帖讨论动漫、分享生活日常。请保持友善，共同维护温馨的社区氛围。', '🎉', '', 1, '2026-07-29 10:00:00'),
(2, 0, '白河千岁', 'img/5.jpg', '重温邻家天使', '最近重温了邻家天使，真昼和周的日常太治愈了！每次看都觉得心里暖暖的，强烈推荐给大家～', '🌸', '', 1, '2026-07-28 15:30:00');
