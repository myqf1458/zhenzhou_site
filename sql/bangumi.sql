-- 请先将下方表创建到你的目标数据库中（如已通过安装向导建库可跳过）
-- USE `your_db_name`;

-- -------------------------------------------
-- 追番列表表 bangumi_follow
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `bangumi_follow` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `season_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'B站 season_id',
  `media_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'B站 media_id',
  `title` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '番剧标题',
  `cover` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '封面图URL',
  `total_count` INT(11) NOT NULL DEFAULT 0 COMMENT '总集数',
  `follow_count` INT(11) NOT NULL DEFAULT 0 COMMENT '已追集数',
  `score` DECIMAL(3,1) NOT NULL DEFAULT 0.0 COMMENT '评分',
  `summary` TEXT COMMENT '简介',
  `area` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '地区',
  `season_type_name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '类型(番剧/国创等)',
  `url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'B站播放链接',
  `sort_order` INT(11) NOT NULL DEFAULT 0 COMMENT '排序(越大越靠前)',
  `is_visible` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否显示 1是 0否',
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_media_id` (`media_id`),
  KEY `idx_sort` (`sort_order`),
  KEY `idx_visible` (`is_visible`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='追番列表';

-- -------------------------------------------
-- 追番配置表 bangumi_config
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `bangumi_config` (
  `config_key` VARCHAR(50) NOT NULL COMMENT '配置键',
  `config_value` TEXT COMMENT '配置值',
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='追番配置';

-- 默认配置：B站用户UID
INSERT INTO `bangumi_config` (`config_key`, `config_value`) VALUES
('bilibili_uid', ''),
('bilibili_cookie', '')
ON DUPLICATE KEY UPDATE `config_key` = `config_key`;
