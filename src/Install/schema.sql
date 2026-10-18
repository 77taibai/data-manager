-- 下载链接管理系统 数据库结构 (MySQL 5.6 兼容)

CREATE TABLE IF NOT EXISTS `repositories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_repositories_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attributes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `repository_id` INT UNSIGNED NOT NULL,
  `key` VARCHAR(50) NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `type` ENUM('text','date','filesize') NOT NULL DEFAULT 'text',
  `required` TINYINT(1) NOT NULL DEFAULT 0,
  `constrained` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attributes_repo_key` (`repository_id`, `key`),
  KEY `idx_attributes_repo` (`repository_id`),
  CONSTRAINT `fk_attributes_repository` FOREIGN KEY (`repository_id`)
    REFERENCES `repositories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attribute_options` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attribute_id` INT UNSIGNED NOT NULL,
  `value` VARCHAR(255) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attribute_options` (`attribute_id`, `value`(180)),
  CONSTRAINT `fk_attribute_options_attribute` FOREIGN KEY (`attribute_id`)
    REFERENCES `attributes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `links` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `repository_id` INT UNSIGNED NOT NULL,
  `url` VARCHAR(1500) NOT NULL,
  `url_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_links_repo_url` (`repository_id`, `url_hash`),
  KEY `idx_links_repo_created` (`repository_id`, `created_at`),
  CONSTRAINT `fk_links_repository` FOREIGN KEY (`repository_id`)
    REFERENCES `repositories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `link_values` (
  `link_id` INT UNSIGNED NOT NULL,
  `attribute_id` INT UNSIGNED NOT NULL,
  `value_text` TEXT NULL,
  `value_date` DATETIME NULL,
  `value_num` BIGINT NULL,
  PRIMARY KEY (`link_id`, `attribute_id`),
  KEY `idx_link_values_text` (`attribute_id`, `value_text`(180)),
  KEY `idx_link_values_date` (`attribute_id`, `value_date`),
  KEY `idx_link_values_num` (`attribute_id`, `value_num`),
  CONSTRAINT `fk_link_values_link` FOREIGN KEY (`link_id`)
    REFERENCES `links` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_link_values_attribute` FOREIGN KEY (`attribute_id`)
    REFERENCES `attributes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `repository_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NULL,
  `key_hash` CHAR(64) NOT NULL,
  `key_prefix` VARCHAR(16) NOT NULL,
  `permissions` ENUM('read','write') NOT NULL DEFAULT 'read',
  `expires_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_keys_hash` (`key_hash`),
  KEY `idx_api_keys_repo` (`repository_id`),
  CONSTRAINT `fk_api_keys_repository` FOREIGN KEY (`repository_id`)
    REFERENCES `repositories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `key` VARCHAR(50) NOT NULL,
  `value` TEXT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
