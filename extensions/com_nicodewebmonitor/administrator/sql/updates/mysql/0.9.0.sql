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
