CREATE TABLE IF NOT EXISTS `#__nwm_sites` (
 `id` int unsigned NOT NULL AUTO_INCREMENT,
 `site_id` char(36) NOT NULL,
 `label` varchar(120) NOT NULL,
 `base_url` varchar(2048) NOT NULL,
 `address` varchar(45) NOT NULL,
 `credential` text NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `site_identity` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__nwm_checks` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `site` int unsigned NOT NULL,
 `checked_at` bigint NOT NULL,
 `collected_at` bigint NULL,
 `outcome` varchar(16) NOT NULL,
 `detail` varchar(40) NOT NULL,
 PRIMARY KEY (`id`), KEY `site_history` (`site`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__nwm_backup_credentials` (
 `site` int unsigned NOT NULL,
 `credential` text NOT NULL,
 PRIMARY KEY (`site`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__nwm_backup_operations` (
 `operation` varchar(64) NOT NULL,
 `site` int unsigned NOT NULL,
 `site_id` char(36) NOT NULL,
 `status` varchar(16) NOT NULL,
 `next_sequence` int unsigned NOT NULL,
 `receipt` text NOT NULL,
 `updated_at` bigint NOT NULL,
 PRIMARY KEY (`operation`), KEY `site_history` (`site`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__nwm_update_credentials` (
 `site` int unsigned NOT NULL, `credential` text NOT NULL, PRIMARY KEY (`site`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `#__nwm_update_operations` (
 `operation` varchar(64) NOT NULL, `site` int unsigned NOT NULL, `site_id` char(36) NOT NULL,
 `status` varchar(16) NOT NULL, `receipt` text NOT NULL, `updated_at` bigint NOT NULL,
 PRIMARY KEY (`operation`), KEY `site_history` (`site`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__nwm_core_update_credentials` (
 `site` int unsigned NOT NULL, `credential` text NOT NULL, PRIMARY KEY (`site`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `#__nwm_core_update_operations` (
 `operation` varchar(64) NOT NULL, `site` int unsigned NOT NULL, `site_id` char(36) NOT NULL,
 `status` varchar(16) NOT NULL, `receipt` text NOT NULL, `updated_at` bigint NOT NULL,
 PRIMARY KEY (`operation`), KEY `site_history` (`site`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__nwm_maintenance_events` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `kind` varchar(16) NOT NULL,
 `operation` varchar(64) NOT NULL,
 `site` int unsigned NOT NULL,
 `site_id` varchar(36) NOT NULL,
 `status` varchar(32) NOT NULL,
 `actor_id` int unsigned NOT NULL,
 `next_sequence` int unsigned DEFAULT NULL,
 `receipt_sha256` char(64) NOT NULL,
 `recorded_at` bigint NOT NULL,
 PRIMARY KEY (`id`), KEY `operation_history` (`kind`,`operation`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
