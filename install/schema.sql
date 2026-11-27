
CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(128) NOT NULL,
  `v` LONGTEXT NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(32) NOT NULL,
  `pwd` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `live_tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token` VARCHAR(64) NOT NULL,
  `kind` VARCHAR(8) NOT NULL DEFAULT 'v4',
  `name` VARCHAR(60) NOT NULL DEFAULT '',
  `idcard` VARCHAR(18) NOT NULL DEFAULT '',
  `order_no` VARCHAR(64) NOT NULL DEFAULT '',
  `state` VARCHAR(10) NOT NULL DEFAULT 'wait',
  `code` INT NOT NULL DEFAULT 0,
  `msg` VARCHAR(255) NOT NULL DEFAULT '',
  `score` DECIMAL(5,2) DEFAULT NULL,
  `passed` TINYINT DEFAULT NULL,
  `cmp_done` TINYINT NOT NULL DEFAULT 0,
  `image_rel` VARCHAR(255) NOT NULL DEFAULT '',
  `image_b64` LONGTEXT NULL,
  `image_mime` VARCHAR(32) NOT NULL DEFAULT '',
  `image_up` VARCHAR(1024) NOT NULL DEFAULT '',
  `live_plugin` VARCHAR(64) NOT NULL DEFAULT '',
  `cmp_plugin` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `expire_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_live_token` (`token`),
  KEY `idx_live_order` (`order_no`),
  KEY `idx_live_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `call_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trace` VARCHAR(40) NOT NULL DEFAULT '',
  `api` VARCHAR(24) NOT NULL DEFAULT '',
  `order_no` VARCHAR(64) NOT NULL DEFAULT '',
  `code` INT NOT NULL DEFAULT 0,
  `http` INT NOT NULL DEFAULT 0,
  `msg` VARCHAR(255) NOT NULL DEFAULT '',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `ua` VARCHAR(255) NOT NULL DEFAULT '',
  `req` LONGTEXT NULL,
  `up_req` LONGTEXT NULL,
  `up_resp` LONGTEXT NULL,
  `resp` LONGTEXT NULL,
  `cost_ms` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_call_time` (`created_at`),
  KEY `idx_call_api_time` (`api`, `created_at`),
  KEY `idx_call_order` (`order_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
